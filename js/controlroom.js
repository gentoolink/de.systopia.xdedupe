/*-------------------------------------------------------+
| SYSTOPIA's Extended Deduper                            |
| Copyright (C) 2019 SYSTOPIA                            |
| Author: B. Endres (endres@systopia.de)                 |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

/**
 * Make sure only one empty picker is showing
 */
function xdedupe_show_pickers() {
    // first: identify the last picker that has a value
    let picker_count = cj("[name^=main_contact_]").length;
    let last_picker = 0;
    for (let i = 1; i <= picker_count; i++) {
        let selector = "[name^=main_contact_" + i + "]";
        if (cj(selector).val().length > 0) {
            last_picker = i;
        }
    }

    // then: show every one before this, and hide every after
    for (let i = 1; i <= picker_count; i++) {
        let selector = "[name^=main_contact_" + i + "]";
        if (i <= last_picker + 1) {
            cj(selector).parent().show();
        } else {
            cj(selector).parent().hide();
        }
    }
}

cj("[name^=main_contact_]")
    .change(xdedupe_show_pickers)
    .parent().hide();
xdedupe_show_pickers();

/**
 * Refresh the AJAX table link to reflect the current settings
 *
 * @returns {string}
 */
function xdedupe_update_table_link() {
    let picker_count = cj("[name^=main_contact_]").length;
    let pickers = [];
    for (let i = 1; i <= picker_count; i++) {
        let selector = "[name^=main_contact_" + i + "]";
        if (cj(selector).val().length > 0) {
            pickers.push(cj(selector).val());
        }
    }
    let url = CRM.vars['xdedupe_controlroom'].xdedupe_data_url + '&pickers=' + pickers.join(',');
    CRM.$('table.xdedupe-result').data({
        "ajax": {
            "url": url,
        }
    });
    return url;
}

/**
 * Trigger a refresh of the AJAX table
 */
function xdedupe_refresh_table() {
    CRM.$('table.xdedupe-result').DataTable().ajax.url(xdedupe_update_table_link()).draw();
}

// trigger this function
(function ($) {
    xdedupe_update_table_link();
})(CRM.$);
cj("#main_contact").change(xdedupe_update_table_link);

// 'merge' button handler
cj("table.xdedupe-result").click(function (e) {
    if (cj(e.target).is("a.xdedupe-merge-individual")) {
        // this is the merge button click -> gather data
        // first visualise the click:
        cj(e.target).addClass("disabled")
            .animate({backgroundColor: "#f00"}, 500)
            .animate({backgroundColor: "transparent"}, 500)
            .animate({backgroundColor: "#f00"}, 500)
            .animate({backgroundColor: "transparent"}, 500)
            .animate({backgroundColor: "#f00"}, 500)
            .animate({backgroundColor: "transparent"}, 500)
            .removeClass("disabled");

        let main_contact_id = cj(e.target).parent().find("span.xdedupe-main-contact-id").text();
        let other_contact_ids = cj(e.target).parent().find("span.xdedupe-other-contact-ids").text();
        let force_merge = cj("#force_merge").prop('checked') ? "1" : "0";
        let merge_log = cj("#merge_log").val();
        let resolvers = cj("#auto_resolve").val();
        if (resolvers == null) {
            resolvers = [];
        }
        let pickers = cj("#main_contact").val();
        if (pickers == null) {
            pickers = [];
        }
        CRM.api3("Xdedupe", "merge", {
            "main_contact_id": main_contact_id,
            "other_contact_ids": other_contact_ids,
            "force_merge": force_merge,
            "merge_log": merge_log,
            "resolvers": resolvers.join(','),
            "pickers": pickers.join(','),
            "dedupe_run": CRM.vars['xdedupe_controlroom'].dedupe_run_id
        }).success(function (result) {
            let ts = CRM.ts('de.systopia.xdedupe');
            if (result.tuples_merged > 0) {
                CRM.alert(ts("Tuple was merged"), ts("Success"), 'info');
                xdedupe_refresh_table();
            } else {
                let errors = result.errors;
                errors = errors.filter(function (el, index, arr) {
                    return index === arr.indexOf(el);
                });
                //let contact_name = CRM.api3('Contact', 'getvalue', {params: {return: 'display_name', id: main_contact_id}});
                let contact_name = CRM.api3('Contact', 'getvalue', {
                    return: 'display_name',
                    id: main_contact_id
                }).then(function (contact_name) {
                    CRM.alert(ts("Merge of '" + contact_name.result + "' failed. Remaining Conflicts: ") + errors.join(', '), ts("Merge Failed"), 'error');
                });
            }
        }).error(function (result) {
            let ts = CRM.ts('de.systopia.xdedupe');
            CRM.alert(ts("Merge failed: ".result.error_msg), ts("Error"), 'error');
        });
        e.preventDefault();

    } else if (cj(e.target).is("a.xdedupe-mark-exception")) {
        // this is the request to mark the tuple as non-dupes
        let main_contact_id = cj(e.target).parent().find("span.xdedupe-main-contact-id").text();
        let other_contact_ids = cj(e.target).parent().find("span.xdedupe-other-contact-ids").text();
        // TODO: proper way: create API call to add to exclude list, AND remove from run
        //   _then_ we can use xdedupe_refresh_table() instead of location.reload()
        let ajax_url = cj("<div/>").html(CRM.vars['xdedupe_controlroom'].exclude_tuple_url).text();
        cj.post(ajax_url,
            {cid: main_contact_id, oid: other_contact_ids, op: 'dupe-nondupe'},
            function (result) {
                cj(e.target).closest("tr").hide();
                // alternative: crudely reload the page
                // if (!window.location.href.endsWith('#xdedupe_results')) {
                //     window.location.replace(window.location.href + '#xdedupe_results');
                // }
                // window.location.reload();
            }, 'json');
        e.preventDefault();
    }
});

