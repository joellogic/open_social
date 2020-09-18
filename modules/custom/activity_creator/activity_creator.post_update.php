<?php

/**
 * @file
 * Contains post update hook implementations.
 */

use Drupal\activity_creator\ActivityInterface;
use Drupal\Core\Site\Settings;

/**
 * Migrate all the activity status information to new table.
 *
 * This is necessary as we have changed the logic of reading notifications
 * and marking them as seen. So, we have migrate the existing activity entries
 * to new table so as to avoid any missing notifications by users.
 */
function activity_creator_post_update_8001_one_to_many_activities(&$sandbox) {
  // Fetching amount of data we need to process.
  // Runs only once per update.
  $connection = \Drupal::database();
  if (!isset($sandbox['total'])) {
    // Get count of all the necessary fields information from current database.
    /** @var \Drupal\Core\Database\Query\Select $query */
    $query = $connection->select('activity__field_activity_recipient_user', 'aur');
    $query->join('activity__field_activity_status', 'asv', 'aur.entity_id = asv.entity_id');
    $number_of_activities = $query
      ->fields('aur', ['entity_id', 'field_activity_recipient_user_target_id'])
      ->fields('asv', ['field_activity_status_value'])
      ->countQuery()
      ->execute()->fetchField();

    // Write total of entities need to be processed to $sandbox.
    $sandbox['total'] = $number_of_activities;
    // Initiate default value for current processing of element.
    $sandbox['current'] = 0;
  }

  // Do not continue if no entities are found.
  if (empty($sandbox['total'])) {
    $sandbox['#finished'] = 1;
    return t('No activities data to be processed.');
  }

  // Get all the necessary fields information from current database.
  /** @var \Drupal\Core\Database\Query\Select $query */
  $query = $connection->select('activity__field_activity_recipient_user', 'aur');
  $query->join('activity__field_activity_status', 'asv', 'aur.entity_id = asv.entity_id');
  $query->addField('aur', 'field_activity_recipient_user_target_id', 'uid');
  $query->addField('aur', 'entity_id', 'aid');
  $query->addField('asv', 'field_activity_status_value', 'status');
  $query->condition('field_activity_recipient_user_target_id', 0, '!=');
  $query->range($sandbox['current'], 5000);

  // Prepare the insert query and execute using previous select query.
  $connection->insert('activity_notification_status')->from($query)->execute();

  // Increment currently processed entities.
  // Check if current starting point is less than our range selection.
  if ($sandbox['total'] - $sandbox['current'] > 5000) {
    $sandbox['current'] += 5000;
  }
  else {
    // If we have less number of results to process, we increment by difference.
    $sandbox['current'] += ($sandbox['total'] - $sandbox['current']);
  }

  // The batch will finish when '#finished' will become '1'.
  $sandbox['#finished'] = ($sandbox['current'] / $sandbox['total']);
  // Print some progress.
  return t('@count activities data has been migrated to activity_notification_table.', ['@count' => $sandbox['current']]);
}

/**
 * Remove activities notification status if related entity not exist.
 *
 * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
 * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
 * @throws \Drupal\Core\Entity\EntityStorageException
 */
function activity_creator_post_update_8802_remove_activities_with_no_related_entities(&$sandbox) {
  // On the first run, we gather all of our initial
  // data as well as initialize all of our sandbox variables to be used in
  // managing the future batch requests.
  if (!isset($sandbox['progress'])) {
    // To set up our batch process, we need to collect all of the
    // necessary data during this initialization step, which will
    // only ever be run once.
    // We start the batch by running some SELECT queries up front
    // as concisely as possible. The results of these expensive queries
    // will be cached by the Batch API so we do not have to look up
    // this data again during each iteration of the batch.
    $database = \Drupal::database();

    // Get activity IDs.
    /** @var \Drupal\Core\Database\Query\Select $query */
    $activity_ids = $database->select('activity_notification_status', 'ans')
      ->fields('ans', ['aid'])
      ->execute()
      ->fetchCol();

    // Now we initialize the sandbox variables.
    // These variables will persist across the Batch API’s subsequent calls
    // to our update hook, without us needing to make those initial
    // expensive SELECT queries above ever again.
    // 'max' is the number of total records we’ll be processing.
    $sandbox['max'] = count($activity_ids);
    // If 'max' is empty, we have nothing to process.
    if (empty($sandbox['max'])) {
      $sandbox['#finished'] = 1;
      return;
    }

    // 'progress' will represent the current progress of our processing.
    $sandbox['progress'] = 0;

    // 'activities_per_batch' is a custom amount that we’ll use to limit
    // how many activities we’re processing in each batch.
    // This is a large part of how we limit expensive batch operations.
    $sandbox['activities_per_batch'] = Settings::get('activity_update_batch_size', 5000);;

    // 'activities_id' will store the activity IDs from activity notification
    // table that we just queried for above during this initialization phase.
    $sandbox['activities_id'] = $activity_ids;

  }

  // Initialization code done. The following code will always run:
  // both during the first run AND during any subsequent batches.
  // Now let’s remove the  missing activity ids.
  $activity_storage = $activity = \Drupal::entityTypeManager()->getStorage('activity');

  // Calculates current batch range.
  $range_end = $sandbox['progress'] + $sandbox['activities_per_batch'];
  if ($range_end > $sandbox['max']) {
    $range_end = $sandbox['max'];
  }

  // Loop over current batch range, creating a new BAR node each time.
  for ($i = $sandbox['progress']; $i < $range_end; $i++) {

    // Take activity ids from $sandbox['activities_id'].
    $activity_id = $sandbox['activities_id'][$i];

    /** @var \Drupal\activity_creator\ActivityInterface $activity */
    $activity = $activity_storage->load($activity_id);

    // Add invalid ids for deletion.
    if (!$activity instanceof ActivityInterface) {
      $aids_for_delete[] = $activity_id;
    }
    // Add not required $activity.
    elseif (is_null($activity->getRelatedEntity())) {
      $aids_for_delete[] = $activity_id;
      $activities_for_delete[$activity_id] = $activity;
    }
  }

  // Remove notifications.
  if (!empty($aids_for_delete)) {
    \Drupal::service('activity_creator.activity_notifications')
      ->deleteNotificationsbyIds($aids_for_delete);
  }

  // Delete not required activity entities.
  if (!empty($activities_for_delete)) {
    $activity_storage->delete($activities_for_delete);
  }

  // Update the batch variables to track our progress.
  // We can calculate our current progress via a mathematical fraction.
  // Drupal’s Batch API will stop executing our update hook as soon as
  // $sandbox['#finished'] == 1 (viz., it evaluates to TRUE).
  $sandbox['progress'] = $range_end;
  $progress_fraction = $sandbox['progress'] / $sandbox['max'];
  $sandbox['#finished'] = empty($sandbox['max']) ? 1 : $progress_fraction;

  // While processing our batch requests, we can send a helpful message
  // to the command line, so developers can track the batch progress.
  if (function_exists('drush_print')) {
    drush_print('Progress: ' . (round($progress_fraction * 100)) . '% (' .
      $sandbox['progress'] . ' of ' . $sandbox['max'] . ' activities processed)');
  }

  // That’s it!
  // The update hook and Batch API manage the rest of the process.
}
