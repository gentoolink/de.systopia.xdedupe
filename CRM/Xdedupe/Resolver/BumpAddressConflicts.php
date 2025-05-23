<?php
/*-------------------------------------------------------+
| SYSTOPIA's Extended Deduper                            |
| Copyright (C) 2019 SYSTOPIA                            |
| Author: B. Endres (endres@systopia.de)                 |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

use CRM_Xdedupe_ExtensionUtil as E;

/**
 * If there is conflicting addresses with the same type, the other contact will have its type changed
 *  to the (new) location type 'conflict'
 */
class CRM_Xdedupe_Resolver_BumpAddressConflicts extends CRM_Xdedupe_Resolver
{

    /** @todo is this enough? */
    static $relevant_address_fields = [
        'street_address',
        'supplemental_address_1',
        'supplemental_address_2',
        'supplemental_address_3',
        'city',
        'postal_code',
        'location_type_id',
        'country_id'
    ];

    /**
     * get the name of the finder
     * @return string name
     */
    public function getName()
    {
        return E::ts("Bump Address Conflicts");
    }

    /**
     * get an explanation what the finder does
     * @return string name
     */
    public function getHelp()
    {
        return E::ts(
            "If there is conflicting addresses with the same type, the address will be changed to the (new) location type 'conflict'"
        );
    }

    /**
     * Resolve the merge conflicts by editing the contact
     *
     * @param $main_contact_id    int     the main contact ID
     * @param $other_contact_ids  array   other contact IDs
     * @return boolean TRUE, if there was a conflict to be resolved
     * @throws Exception if the conflict couldn't be resolved
     */
    public function resolve($main_contact_id, $other_contact_ids)
    {
        $changes_made = false;
        
        // First, clean up any existing conflict addresses
        try {
            $conflict_location_type_id = CRM_Xdedupe_Config::getConflictLocationTypeID();
            $main_contact_addresses = $this->getContactAddresses($main_contact_id);
            
            // Clean up main contact's conflict addresses
            foreach ($main_contact_addresses as $address_id => $address) {
                if ($address['location_type_id'] == $conflict_location_type_id) {
                    // Try to find a non-conflict address of the same type
                    $non_conflict_address = $this->findNonConflictAddress($main_contact_id, $address);
                    if ($non_conflict_address) {
                        // Update the conflict address to match the non-conflict one
                        civicrm_api3('Address', 'create', [
                            'id' => $address_id,
                            'location_type_id' => $non_conflict_address['location_type_id'],
                            'street_address' => $non_conflict_address['street_address'],
                            'city' => $non_conflict_address['city'],
                            'postal_code' => $non_conflict_address['postal_code'],
                            'country_id' => $non_conflict_address['country_id'],
                            'is_primary' => $address['is_primary']
                        ]);
                        $this->addMergeDetail(
                            E::ts(
                                "Updated conflict address [%1] to match existing address type",
                                [1 => $address_id]
                            )
                        );
                        $changes_made = true;
                    }
                }
            }
            
            // Now handle the other contacts
            foreach ($other_contact_ids as $other_contact_id) {
                $other_contact_addresses = $this->getContactAddresses($other_contact_id);
                
                // Clean up other contact's conflict addresses
                foreach ($other_contact_addresses as $address_id => $address) {
                    if ($address['location_type_id'] == $conflict_location_type_id) {
                        // Try to find a non-conflict address of the same type
                        $non_conflict_address = $this->findNonConflictAddress($other_contact_id, $address);
                        if ($non_conflict_address) {
                            // Update the conflict address to match the non-conflict one
                            civicrm_api3('Address', 'create', [
                                'id' => $address_id,
                                'location_type_id' => $non_conflict_address['location_type_id'],
                                'street_address' => $non_conflict_address['street_address'],
                                'city' => $non_conflict_address['city'],
                                'postal_code' => $non_conflict_address['postal_code'],
                                'country_id' => $non_conflict_address['country_id'],
                                'is_primary' => $address['is_primary']
                            ]);
                            $this->addMergeDetail(
                                E::ts(
                                    "Updated conflict address [%1] to match existing address type",
                                    [1 => $address_id]
                                )
                            );
                            $changes_made = true;
                        }
                    }
                }

                // Handle address conflicts
                foreach ($main_contact_addresses as $main_address_id => $main_address) {
                    foreach ($other_contact_addresses as $other_address_id => $other_address) {
                        if ($main_address['location_type_id'] == $other_address['location_type_id']) {
                            if ($this->addressEquals($main_address, $other_address)) {
                                // Addresses are identical - delete the duplicate
                                try {
                                    civicrm_api3('Address', 'delete', ['id' => $other_address_id]);
                                    $this->addMergeDetail(
                                        E::ts(
                                            "Removed duplicate address [%1] from contact [%2] as it was identical to main contact's address.",
                                            [
                                                1 => $other_address_id,
                                                2 => $other_contact_id
                                            ]
                                        )
                                    );
                                    $changes_made = true;
                                    $this->merge->unloadContact($other_contact_id);
                                } catch (Exception $e) {
                                    $this->addMergeDetail(
                                        E::ts(
                                            "ERROR: Failed to remove duplicate address [%1] from contact [%2]: %3",
                                            [
                                                1 => $other_address_id,
                                                2 => $other_contact_id,
                                                3 => $e->getMessage()
                                            ]
                                        )
                                    );
                                    CRM_Core_Error::debug_log_message("XDedupe: Failed to remove duplicate address: " . $e->getMessage());
                                }
                            } else {
                                // Addresses differ - mark as conflict
                                try {
                                    $is_primary = !empty($other_address['is_primary']);
                                    $result = civicrm_api3(
                                        'Address',
                                        'create',
                                        [
                                            'id' => $other_address_id,
                                            'location_type_id' => $conflict_location_type_id,
                                            'is_primary' => $is_primary ? 1 : 0
                                        ]
                                    );
                                    
                                    if (!empty($result['is_error'])) {
                                        throw new Exception("Failed to update address location type: " . $result['error_message']);
                                    }

                                    $this->addMergeDetail(
                                        E::ts(
                                            "Address [%1] from contact [%2] was bumped to 'conflict' location type (preserved primary status: %3).",
                                            [
                                                1 => $other_address_id,
                                                2 => $other_address['contact_id'],
                                                3 => $is_primary ? 'yes' : 'no'
                                            ]
                                        )
                                    );
                                    $changes_made = true;
                                    $this->merge->unloadContact($other_contact_id);
                                } catch (Exception $e) {
                                    $this->addMergeDetail(
                                        E::ts(
                                            "ERROR: Failed to resolve address conflict for address [%1] from contact [%2]: %3",
                                            [
                                                1 => $other_address_id,
                                                2 => $other_contact_id,
                                                3 => $e->getMessage()
                                            ]
                                        )
                                    );
                                    CRM_Core_Error::debug_log_message("XDedupe: Address conflict resolution failed: " . $e->getMessage());
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $this->addMergeDetail(
                E::ts(
                    "ERROR: Address conflict resolution failed: %1",
                    [1 => $e->getMessage()]
                )
            );
            CRM_Core_Error::debug_log_message("XDedupe: Address conflict resolution failed: " . $e->getMessage());
            throw $e;
        }

        return $changes_made;
    }

    /**
     * Get the given contact's address records
     *
     * @param $contact_id int contact ID
     *
     * @return array id => address data
     */
    protected function getContactAddresses($contact_id)
    {
        $query = civicrm_api3(
            'Address',
            'get',
            [
                'contact_id'   => $contact_id,
                'option.limit' => 0,
                'sequential'   => 0,
                'return'       => 'id,' . implode(',', self::$relevant_address_fields) . ',is_primary'
            ]
        );
        return $query['values'];
    }

    /**
     * Find a non-conflict address that matches the given address
     * 
     * @param int $contact_id
     * @param array $address
     * @return array|null
     */
    protected function findNonConflictAddress($contact_id, $address)
    {
        $conflict_location_type_id = CRM_Xdedupe_Config::getConflictLocationTypeID();
        $addresses = $this->getContactAddresses($contact_id);
        
        foreach ($addresses as $existing_address) {
            if ($existing_address['location_type_id'] != $conflict_location_type_id &&
                $this->addressEquals($existing_address, $address)) {
                return $existing_address;
            }
        }
        
        return null;
    }

    /**
     * Check if the address is the same according to the attributes
     * @param $address1 array address data
     * @param $address2 array address data
     * @return boolean are they equal?
     */
    protected function addressEquals($address1, $address2)
    {
        foreach (self::$relevant_address_fields as $attribute) {
            $value1 = CRM_Utils_Array::value($attribute, $address1, '');
            $value2 = CRM_Utils_Array::value($attribute, $address2, '');
            if ($value1 != $value2) {
                return false;
            }
        }
        return true;
    }
}
