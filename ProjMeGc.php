<?php
namespace Stanford\ProjMeGc;

include_once("emLoggerTrait.php");
use \REDCap;

/**
 * This is a custom EM made for Colleen Caleshu
 * Class ProjMeGc
 * @package Stanford\ProjMeGc
 */
class ProjMeGc extends \ExternalModules\AbstractExternalModule
{
    use emLoggerTrait;

    public $survey_record;
    public $main_pk;
    public $main_record;

    const SETUP_FORM  = 'setup';
    const SURVEY_NAME = 'ema_survey';
    const SMS_PREFIX  = 'Please complete your survey here: ';


    function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1 )
    {
        // Setup Form
        if ($instrument == self::SETUP_FORM) {
            $this->updateSetupForm($project_id, $record, $instrument, $event_id);
        }
    }


    /**
     * @param $project_id
     * @param $record
     * @param $instrument
     * @param $event_id
     */
    public function updateSetupForm($project_id, $record, $instrument, $event_id) {
        try {
            $log = [];

            // Get data from setup form
            $q       = REDCap::getData('json', array($record), null, array($event_id));
            $records = json_decode($q, true);
            $setup   = $records[0];

            // Don't do anything if auto-calc isn't checked
            $auto_calc = $setup['auto_calc_schedule___1'];
            // $this->emDebug($setup, $auto_calc);
            if (!$auto_calc) return;

            // Get important field values
            $d0_ema_date           = $setup['t0_ema_date'];
            $d0_dt_immutable       = new \DateTimeImmutable($d0_ema_date);
            $invite_offset_minutes = $setup['invite_offset_minutes'];
            $randomization         = $setup['randomization'];

            // Clean up phone number
            $phone_number          = preg_replace('/\D/','',$setup['phone_number']);

            // Build arrays to quickly loop through possible field/day combinations (this is very custom to project dict)
            $days    = array(1, 2, 3);
            $offsets = array('a' => "9:00", 'b' => "11:30", 'c' => "14:00");
            $tdays   = array(0, 1, 2);

            // Build data to upload to record setup
            $data = array();
            foreach ($tdays as $t) {
                foreach ($offsets as $o => $offset) {
                    foreach ($days as $day) {
                        if ($t == 2 && $randomization != "1") {
                            // skip t2 when randomization is not 1 (project-specific)
                            $dt = "";
                        } else {
                            // Set day
                            $this_date = $d0_dt_immutable->modify('+' . ($day) . 'days')->format("Y-m-d");
                            $dt = $this_date . " " . $this->getRandomTime($offset, $invite_offset_minutes);
                        }
                        $data["t" . $t . "_ema_day" . $day . $o] = $dt;
                    }
                }
            }

            // Reset calc button
            $data['auto_calc_schedule___1'] = "0";

            // Merge new cells into original record
            $payload = array_merge($setup, $data);

            // Save record
            $result = REDCap::saveData('json', json_encode(array($payload)), 'overwrite');

            if (empty($result['errors'])) {
                // $log[] = "AutoCalculated Start Times";
            } else {
                $this->emError("Errors saving dates", $result);
            }

            // NEXT WE NEED TO CHECK SURVEYS SCHEDULED
            $completedEvents = $this->getCompletedSurveyEvents($record, self::SURVEY_NAME);
            list($survey_id, $survey_name) = $this->getSurveyId( $project_id,self::SURVEY_NAME);
            // $this->emDebug($completedEvents, $survey_id);

            foreach ($tdays as $t) {
                foreach ($offsets as $o => $offset) {
                    foreach ($days as $day) {
                        $field = "t" . $t . "_ema_day" . $day . $o;
                        $scheduled_time_to_send = $data[$field];

                        $event_name = $field . "_arm_1";
                        $event_id = REDCap::getEventIdFromUniqueEvent($event_name);


                        // Check if survey is already complete!
                        if (in_array($event_id, $completedEvents)) {
                            $msg = "$event_name already complete";
                            $log[] = $msg;
                            $this->emDebug($msg);
                            continue;
                        }

                        // Get Survey URL - this will create a participant_id if it doesn't already exist
                        $url = REDCap::getSurveyLink($record,self::SURVEY_NAME, $event_id);

                        // Get the participant id (needed later)
                        list($participant_id, $response_id) = $this->getParticipantAndResponseId($survey_id, $record, $event_id);

                        // Build the email_id with the SMS message
                        //$this->emDebug($field, $event_id, $event_name, $url);
                        $message = self::SMS_PREFIX . $url;
                        $email_id = $this->insertRedcapSurveysEmail($survey_id, $message);
                        if ($email_id === false) {
                            $msg = "Error creating email_id for $field - $event_name - $event_id - $survey_id - $message";
                            $log[] = "Error creating email_id for $event_name";
                            $this->emError($msg);
                            continue;
                        }
                        // $this->emDebug($email_id);

                        // Make Email Recipient ID
                        $email_recip_id = $this->insertRedcapSurveysEmailRecipient($email_id, $participant_id, $phone_number);
                        if ($email_recip_id === false) {
                            $msg = "Error making email recipient id from $email_id, $participant_id, $phone_number";
                            $log[] = $msg;
                            $this->emError($msg);
                            continue;
                        }
                        // $this->emDebug($email_recip_id);

                        // Remove future email if already on queue (in case of change of date)
                        $result = $this->removeFromSurveyQueue($survey_id, $record, $event_id);
                        if ($result > 0) {
                            $log[] = "Removed queued invitation in $event_name";
                        }


                        // Schedule Delivery
                        if(empty($scheduled_time_to_send)) {
                            // Don't schedule anything that is blank
                            $this->emDebug("No survey scheduled for $field");
                            continue;
                        } else {
                            // Format the date
                            $dt = new \DateTime($scheduled_time_to_send);
                            $dt->format("Y-m-d H:i:s");

                            // Add it to the queue
                            $this->addToSurveyQueue($email_recip_id, $record, $scheduled_time_to_send);
                            $log[] = "Scheduled $event_name for $scheduled_time_to_send";
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->emError("Exception!", $e);
        }

        // Log
        if (!empty($log)) REDCap::logEvent("ProjMeGc Update", implode("\n",$log), "", $record, $event_id);
    }


    /**
     * @param $email_recip_id
     * @param $record
     * @param $scheduled_time_to_send
     * @return bool|int|string
     * @throws \Exception
     */
    public function addToSurveyQueue($email_recip_id, $record, $scheduled_time_to_send) {

        $sql = sprintf("insert into redcap_surveys_scheduler_queue set email_recip_id = %d, record = '%s', scheduled_time_to_send = '%s'",
            filter_var($email_recip_id, FILTER_VALIDATE_INT),
            db_real_escape_string($record),
            db_real_escape_string($scheduled_time_to_send)
        );
        $q = $this->query($sql);
        $id = $q ? db_insert_id() : false;
        // $this->emDebug($sql, $q, $id);
        return $id;
    }


    /**
     * DELETE FORM SURVEY QUEUE ANY UPCOMING SURVEYS FOR THIS RECORD/EVENT/SURVEY COMBO
     * @param $survey_id
     * @param $record
     * @param $event_id
     * @return int Rows Affected by Deletion
     */
    public function removeFromSurveyQueue($survey_id, $record, $event_id) {
        $sql = sprintf("
            delete rsrq 
            from redcap_surveys_scheduler_queue rsrq
                join redcap_surveys_emails_recipients rser on rsrq.email_recip_id = rser.email_recip_id
                join redcap_surveys_participants rsp on rser.participant_id = rsp.participant_id
            where rsrq.status = 'QUEUED'
                and rsp.survey_id = %d
                and rsp.event_id = %d
                and rsrq.record = '%s'",
            filter_var($survey_id, FILTER_VALIDATE_INT),
            filter_var($event_id, FILTER_VALIDATE_INT),
            db_real_escape_string($record)
        );
        $q = $this->query($sql);
        $affectedRows = db_affected_rows();
        // if ($affectedRows) $this->emDebug("For record $record / " . REDCap::getEventNames(true,true, $event_id) . " $affectedRows queued invites were removed");
        return $affectedRows;
    }


    /**
     * Insert email/sms template into table - content should contain SMS survey url
     * @param $survey_id
     * @param $email_content
     * @return bool|int|string
     */
    public function insertRedcapSurveysEmail($survey_id, $email_content) {
        $sql = sprintf("insert into redcap_surveys_emails set survey_id = %d, email_content = '%s', append_survey_link = 0",
            filter_var($survey_id, FILTER_VALIDATE_INT),
            db_real_escape_string($email_content)
        );
        $q = $this->query($sql);
        $id = $q ? db_insert_id() : false;
        // $this->emDebug($sql,$q, $id);
        return $id;
    }


    /**
     * Link the email_id to a participant
     * @param $email_id
     * @param $participant_id
     * @param $static_phone
     * @return bool|int|string
     */
    public function insertRedcapSurveysEmailRecipient($email_id, $participant_id, $static_phone) {
        $sql = sprintf("insert into redcap_surveys_emails_recipients set email_id = %d, participant_id = %d, static_phone = '%s', delivery_type='SMS_INVITE_WEB'",
            filter_var($email_id, FILTER_VALIDATE_INT),
            filter_var($participant_id, FILTER_VALIDATE_INT),
            db_real_escape_string($static_phone)
        );
        $q = $this->query($sql);
        $id = $q ? db_insert_id() : false;
        // $this->emDebug($sql,$q, $id);
        return $id;
    }


    /**
     * Return array of event_ids where survey is already completed or form_status = 2 (completed internally)
     * @param $record
     * @param $form_name
     * @return array
     */
    public function getCompletedSurveyEvents($record, $form_name) {
        $sql = sprintf("
            select rsr.record, rsr.completion_time, rsp.event_id, rd.value as 'form_status' 
            from redcap_surveys_response rsr
            join redcap_surveys_participants rsp on rsr.participant_id = rsp.participant_id
            join redcap_surveys rs on rs.survey_id = rsp.survey_id
            left outer join redcap_data rd on rd.project_id = rs.project_id and rd.record = rsr.record and rd.event_id = rsp.event_id and field_name = '%s_complete'
            where
                rs.project_id = 61
            and rs.form_name = '%s'
            and rsr.record = '%s';",
            db_real_escape_string($form_name),
            db_real_escape_string($form_name),
            db_real_escape_string($record));
        $q = $this->query($sql);
        $events = [];
        while ($row = db_fetch_array($q)) {
            $event_id = $row['event_id'];
            $completion_time = $row['completion_time'];
            $form_status = $row['form_status'];
            if (!empty($completion_time) || $form_status == '2') {
                // event is complete (either by survey or form_status)
                array_push($events, $event_id);
            }
        }
        // $this->emDebug("getCompletedSurveyEvents", $events);
        return $events;
    }


    /**
     * Given a start time, return a random time that takes into account the minute offset and random window
     * @param     $startTime "11:00"
     * @param int $minuteOffset "-120"
     * @param int $randomMinuteWindow "150" (for 2 1/2 hours)
     * @return string
     * @throws \Exception
     */
    public function getRandomTime($startTime, $minuteOffset=0, $randomMinuteWindow=150) {
        $rand_minute = rand(0,$randomMinuteWindow);
        $dt = new \DateTime($startTime);
        $offset = $minuteOffset + $rand_minute;

        $dt_int = new \DateInterval ('PT' . abs($offset) . 'M');
        if ($offset < 0) {
            $dt_int->invert = 1; //Make it negative
        }
        $dt->add($dt_int);


        //$this->emDebug("start + offset + random: ".$startTime . "+" . $minuteOffset . "+" . $rand_minute . " = " . $dt->format("H:i"));
        return $dt->format("H:i");
    }
}