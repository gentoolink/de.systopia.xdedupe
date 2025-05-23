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
 * This is the actual merge process
 */
class CRM_Xdedupe_Merge
{

    protected $resolvers = [];
    protected $required_contact_attributes = null;
    protected $force_merge = false;
    protected $stats = [];
    protected $merge_log = null;
    protected $merge_details = [];
    protected $merge_log_handle = null;
    protected $_contact_cache = [];

    public function __construct($params)
    {
        // initialise stats
        $this->stats = [
            'tuples_merged'      => 0,
            'contacts_merged'    => 0,
            'conflicts_resolved' => 0,
            'aborted'            => 0,
            'errors'             => [],
            'failed'             => [],
        ];

        // get resolvers and the required attributes
        $this->resolvers             = [];
        $required_contact_attributes = ['is_deleted', 'contact_type'];
        $resolver_classes            = CRM_Utils_Array::value('resolvers', $params, '');
        if (is_string($resolver_classes)) {
            $resolver_classes = explode(',', $resolver_classes);
        }
        foreach ($resolver_classes as $resolver_class) {
            $resolver_class = trim($resolver_class);
            if (empty($resolver_class)) {
                continue;
            }
            $resolver = CRM_Xdedupe_Resolver::getResolverInstance($resolver_class, $this);
            if ($resolver) {
                $this->resolvers[]           = $resolver;
                $required_contact_attributes = array_merge(
                    $required_contact_attributes,
                    $resolver->getContactAttributes()
                );
            } else {
                $this->logError("Resolver class '{$resolver_class}' not found!");
            }
        }
        $this->required_contact_attributes = implode(',', $required_contact_attributes);

        // set force merge
        $this->force_merge = !empty($params['force_merge']);

        // initialise merge_log
        if (!empty($params['merge_log'])) {
            $this->merge_log = $params['merge_log'];
            $this->merge_log_handle = fopen($this->merge_log, 'a');
        } else {
            $this->merge_log = null;
            $this->merge_log_handle = null;
        }

        $this->log("Initialised merger: " . json_encode($params));
    }

    public function __destruct()
    {
        if ($this->merge_log_handle) {
            fclose($this->merge_log_handle);
        }
    }

    /**
     * Get the stats from all the merges performed by this object
     *
     * @param boolean summary
     *   don't return 'failed' and 'errors' as list, but as aggregation
     *
     * @return array stats
     *   stats
     */
    public function getStats($summary = false)
    {
        if ($summary) {
            // flatten error / failure lists
            $stats           = $this->stats;
            $stats['failed'] = count($this->stats['failed']);

            $error_counts = [];
            foreach ($stats['errors'] as $error_message) {
                $error_counts[$error_message] = CRM_Utils_Array::value($error_message, $error_counts, 0) + 1;
            }
            $stats['errors'] = $error_counts;
            return $stats;
        } else {
            return $this->stats;
        }
    }

    /**
     * Mark the merge process as aborted, pro
     *
     * @param string $reason
     *   abortion reason
     */
    public function setAborted($reason)
    {
        $this->stats['aborted'] = $reason;
    }

    /**
     * Log a general merge message to the merge log
     *
     * @param $message string message
     */
    public function log($message)
    {
        if (empty($this->merge_log_handle)) {
            CRM_Core_Error::debug_log_message("XMERGE: {$message}");
        } else {
            $message = date('[Y-m-d H:i:s] ') . $message . "\n";
            fputs($this->merge_log_handle, $message);
        }
    }

    /**
     * Log an error message to the merge log, and the internal error counter
     *
     * @param $message string message
     */
    public function logError(string $message)
    {
        $this->stats['errors'][] = $message;
        $this->log("ERROR: " . $message);
    }

    /**
     * @param $main_contact_id   int   main contact ID
     * @param $other_contact_ids array other contact IDs
     */
    public function multiMerge($main_contact_id, $other_contact_ids)
    {
        $this->log("Merging into contact [{$main_contact_id}]: [" . implode(',', $other_contact_ids) . ']');

        // first check for poor judgement:
        if (in_array($main_contact_id, $other_contact_ids)) {
            throw new Exception("Cannot merge contact(s) with itself!");
        }

        // do some more verification here
        $contact_ids   = $other_contact_ids;
        $contact_ids[] = $main_contact_id;
        $this->loadContacts($contact_ids);
        $main_contact = $this->getContact($main_contact_id);
        if (!empty($main_contact['is_deleted'])) {
            $this->logError("Main contact [{$main_contact_id}] is deleted. This is wrong!");
            return;
        }

        // TODO: run multi-resolvers? problem is, that it might resolve contacts that then don't get merged after all...
        //
        //    foreach ($this->resolvers as $resolver) {
        //      $changes = $resolver->resolve($main_contact_id, $other_contact_ids);
        //      if ($changes) {
        //        $this->stats['conflicts_resolved'] += 1;
        //        $this->unloadContact($main_contact_id);
        //      }
        //    }

        // now simply merge all contacts individually:
        $merge_succeeded = true;
        foreach ($other_contact_ids as $other_contact_id) {
            $merge_succeeded &= $this->merge($main_contact_id, $other_contact_id, true);
        }
        if ($merge_succeeded) {
            $this->stats['tuples_merged'] += 1;
        }
    }

    /**
     * Merge two contacts
     *
     * @param int $main_contact_id
     * @param int $other_contact_id
     * @param bool $force_merge
     * @return bool
     */
    public function merge($main_contact_id, $other_contact_id, $force_merge = false)
    {
        $merge_succeeded = false;
        $transaction = null;
        
        try {
            // Verify contacts exist and are not deleted
            $main_contact = civicrm_api3('Contact', 'get', [
                'id' => $main_contact_id,
                'is_deleted' => 0,
                'return' => ['id', 'contact_type', 'is_deleted']
            ]);
            
            if (empty($main_contact['values'])) {
                $this->addMergeDetail(E::ts("Main contact [%1] not found or is deleted", [1 => $main_contact_id]));
                return false;
            }
            
            $other_contact = civicrm_api3('Contact', 'get', [
                'id' => $other_contact_id,
                'is_deleted' => 0,
                'return' => ['id', 'contact_type', 'is_deleted']
            ]);
            
            if (empty($other_contact['values'])) {
                $this->addMergeDetail(E::ts("Other contact [%1] not found or is deleted", [1 => $other_contact_id]));
                return false;
            }
            
            // Start transaction
            $transaction = new CRM_Core_Transaction();
            
            // Run pre-merge resolvers
            foreach ($this->resolvers as $resolver) {
                try {
                    $resolver->resolve($main_contact_id, [$other_contact_id]);
                } catch (Exception $e) {
                    $this->addMergeDetail(
                        E::ts(
                            "ERROR: Resolver %1 failed: %2",
                            [1 => get_class($resolver), 2 => $e->getMessage()]
                        )
                    );
                    CRM_Core_Error::debug_log_message("XDedupe: Resolver " . get_class($resolver) . " failed: " . $e->getMessage());
                    if (!$force_merge) {
                        throw $e;
                    }
                }
            }
            
            // Check for conflicts before merging
            try {
                $conflicts = civicrm_api3('Contact', 'get_merge_conflicts', [
                    'to_keep_id' => $main_contact_id,
                    'to_remove_id' => $other_contact_id
                ]);
                
                if (!empty($conflicts['values'])) {
                    $this->addMergeDetail(E::ts("Found conflicts before merge:"));
                    foreach ($conflicts['values'] as $merge_mode => $conflict_data) {
                        if (!empty($conflict_data['conflicts'])) {
                            foreach ($conflict_data['conflicts'] as $entity => $entity_conflicts) {
                                foreach ($entity_conflicts as $field_name => $field_conflict) {
                                    $this->addMergeDetail(
                                        E::ts(
                                            "Potential conflict in %1: %2",
                                            [
                                                1 => $entity,
                                                2 => $field_conflict['title']
                                            ]
                                        )
                                    );
                                }
                            }
                        }
                    }
                    
                    if (!$force_merge) {
                        throw new Exception("Merge aborted due to conflicts");
                    }
                }
            } catch (Exception $e) {
                // If the API call fails, log it but continue if force_merge is true
                $this->addMergeDetail(
                    E::ts(
                        "WARNING: Could not check for conflicts: %1",
                        [1 => $e->getMessage()]
                    )
                );
                if (!$force_merge) {
                    throw $e;
                }
            }
            
            // Perform the merge
            $result = civicrm_api3('Contact', 'merge', [
                'to_keep_id' => $main_contact_id,
                'to_remove_id' => $other_contact_id,
                'mode' => $force_merge ? 'aggressive' : 'safe'
            ]);
            
            if (!empty($result['is_error'])) {
                throw new Exception("Merge failed: " . $result['error_message']);
            }
            
            // Verify merge success
            $other_contact_check = civicrm_api3('Contact', 'get', [
                'id' => $other_contact_id,
                'is_deleted' => 0
            ]);
            
            if (!empty($other_contact_check['values'])) {
                $this->addMergeDetail(E::ts("WARNING: Other contact [%1] still exists after merge", [1 => $other_contact_id]));
                if (!$force_merge) {
                    throw new Exception("Merge verification failed - other contact still exists");
                }
            }
            
            // Run post-merge resolvers
            foreach ($this->resolvers as $resolver) {
                try {
                    $resolver->postProcess($main_contact_id);
                } catch (Exception $e) {
                    $this->addMergeDetail(
                        E::ts(
                            "WARNING: Post-process for resolver %1 failed: %2",
                            [1 => get_class($resolver), 2 => $e->getMessage()]
                        )
                    );
                    CRM_Core_Error::debug_log_message("XDedupe: Post-process for resolver " . get_class($resolver) . " failed: " . $e->getMessage());
                }
            }
            
            $merge_succeeded = true;
            $this->addMergeDetail(E::ts("Successfully merged contact [%1] into [%2]", [1 => $other_contact_id, 2 => $main_contact_id]));
            
        } catch (Exception $e) {
            $this->addMergeDetail(
                E::ts(
                    "ERROR: Merge failed: %1",
                    [1 => $e->getMessage()]
                )
            );
            CRM_Core_Error::debug_log_message("XDedupe: Merge failed: " . $e->getMessage());
            
            if ($transaction) {
                $transaction->rollback();
            }
            
            return false;
        }
        
        return $merge_succeeded;
    }


    /**
     * Load the given contact IDs into the internal contact cache
     *
     * @param $contact_ids array list of contact IDs
     * @return array list of contact IDs that have been loaded into cache, the other ones were already in there
     */
    public function loadContacts($contact_ids)
    {
        // first: check which ones are already there
        $contact_ids_to_load = [];
        foreach ($contact_ids as $contact_id) {
            if (!isset($this->_contact_cache[$contact_id])) {
                $contact_ids_to_load[] = $contact_id;
            }
        }

        // load remaining contacts
        if (!empty($contact_ids_to_load)) {
            $query = civicrm_api3(
                'Contact',
                'get',
                [
                    'id'           => ['IN' => $contact_ids_to_load],
                    'option.limit' => 0,
                    'return'       => $this->required_contact_attributes,
                    'sequential'   => 0
                ]
            );
            foreach ($query['values'] as $contact) {
                $this->_contact_cache[$contact['id']] = $contact;
            }
        }

        return $contact_ids_to_load;
    }

    /**
     * Remove the given contact ID from cache, e.g. when we know it's changed
     */
    public function unloadContact($contact_id)
    {
        unset($this->_contact_cache[$contact_id]);
    }

    /**
     * Get the single contact. If it's not cached, load it first
     * @param $contact_id int contact ID to load
     *
     * @return array contact data
     */
    public function getContact($contact_id)
    {
        if (!isset($this->_contact_cache[$contact_id])) {
            $this->loadContacts([$contact_id]);
        }
        return $this->_contact_cache[$contact_id];
    }

    /**
     * Reset the merge detail stack
     */
    public function resetMergeDetails()
    {
        $this->merge_details = [];
    }

    /**
     * Add a merge detail (detailed merge changes)
     *
     * @param $information string info
     */
    public function addMergeDetail($information)
    {
        $this->merge_details[] = $information;
    }

    /**
     * The last activity
     *
     * @return array merge details
     */
    public function getMergeDetails($main_contact_id)
    {
        return $this->merge_details;
    }

    /**
     * Get the ID of the last merge activity.
     * @param $contact_id integer contact ID
     * @return null|integer activity id
     */
    public function getLastMergeActivityID($contact_id)
    {
        $contact_id             = (int)$contact_id;
        $merge_activity_type_id = (int)CRM_Xdedupe_Config::getMergeActivityTypeID();
        if (!$merge_activity_type_id || !$contact_id) {
            return null;
        }

        // find activity
        return CRM_Core_DAO::singleValueQuery(
            "
            SELECT activity.id AS activity_id
            FROM civicrm_activity activity
            LEFT JOIN civicrm_activity_contact ac ON ac.activity_id = activity.id
            WHERE ac.contact_id = {$contact_id}
              AND ac.record_type_id = 3
              AND activity.activity_type_id = {$merge_activity_type_id}
              -- AND activity.activity_date_time BETWEEN (NOW() - INTERVAL 10 SECOND) AND (NOW() + INTERVAL 10 SECOND)
            ORDER BY activity.id DESC
            LIMIT 1;"
        );
    }


    /**
     * Copy the merge note into the details of the merge activity
     *
     * @return boolean if successfull
     */
    public function updateMergeActivity($main_contact_id)
    {
        $merge_details = $this->getMergeDetails($main_contact_id);
        if (!empty($merge_details)) {
            $activity_id = $this->getLastMergeActivityID($main_contact_id);
            if (!$activity_id) {
                // not found
                return false;
            }

            // update activity
            civicrm_api3(
                'Activity',
                'create',
                [
                    'id'      => $activity_id,
                    'details' => implode("<br/>", $merge_details),
                ]
            );
        }
        return true;
    }

    /**
     * Create a new not with the contact adding the merge details
     *
     * @param $contact_id int    contact ID the merge detail should be recorded
     * @param $subject    string the subject line
     */
    public function createMergeDetailNote($contact_id, $subject = "Merge Details")
    {
        $merge_details = $this->getMergeDetails($contact_id);
        if (!empty($merge_details)) {
            civicrm_api3(
                'Note',
                'create',
                [
                    'entity_id'    => $contact_id,
                    'entity_table' => 'civicrm_contact',
                    'note'         => implode("\n", $merge_details),
                    'subject'      => $subject
                ]
            );
        }
    }

    /**
     * Regenerate @uniqueID which is used for log_conn_id in log tables
     */
    private function resetLogId()
    {
        CRM_Core_DAO::executeQuery(
            'SET @uniqueID = %1',
            [
                1 => [
                    uniqid() . CRM_Utils_String::createRandom(4, CRM_Utils_String::ALPHANUMERIC),
                    'String',
                ]
            ]
        );
    }
}
