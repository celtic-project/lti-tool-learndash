<?php
/*
 *  lti-tool-learndash - WordPress module to integrate LTI support with LearnDash
 *  Copyright (C) 2024  Stephen P Vickers
 *
 *  Author: stephen@spvsoftwareproducts.com
 */

use ceLTIc\LTI\Profile;
use ceLTIc\LTI\Service;

require_once(dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'lti-tool' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php');
require_once(dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'lti-tool' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'WPTool.php');

/**
 * Override Tool object used by the LTI Tool plugin.
 */
class LTI_Tool_LearnDash extends LTI_Tool_WPTool
{

    public function __construct($data_connector)
    {
        parent::__construct($data_connector);
        $this->product = new Profile\Item('29d05a6d-5806-452f-b054-ba60bd8e93fe', 'LearnDash',
            'The most powerful learning management system for WordPress.', 'https://www.learndash.com');
        $requiredMessages = array(new Profile\Message('basic-lti-launch-request', '?lti-tool',
                array('User.id', 'Membership.role', 'Person.name.full', 'Person.name.family', 'Person.name.given', 'Person.email.primary', 'Context.id')));
        $this->resourceHandlers = array(new Profile\ResourceHandler(
                new Profile\Item('ld', 'LearnDash LMS', 'Learning Management System.'),
                '?' . LTI_TOOL_LEARNDASH_PLUGIN_NAME . '&icon', $requiredMessages, array()));
        if (!isset($this->requiredScopes[Service\Score::$SCOPE])) {
            $this->requiredScopes[] = Service\Score::$SCOPE;
        }
    }

    /**
     * Handle a launch message.
     *
     * Redirect users to the course as denoted in the 'course' custom parameter (if available to the launching platform).
     *
     * @global type $lti_tool_session
     */
    protected function onLaunch(): void
    {
        global $lti_tool_session;

        $options = lti_tool_get_options();
        $this->ok = !empty($this->messageParameters['custom_course']);
        if (!$this->ok) {
            $this->reason = 'Missing custom parameter';
        } else {
            require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'LTI_Tool_LearnDash_Binary_Selector_Courses.php');
            $course_selector = new LTI_Tool_LearnDash_Binary_Selector_Courses();
            $courses = $course_selector->getCourses();
            $this->ok = false;
            foreach ($courses as $course) {
                if ($course->post_name === $this->messageParameters['custom_course']) {
                    $this->ok = true;
                    break;
                }
            }
            if (!$this->ok) {
                $this->reason = 'Course not found';
            }
        }
        if ($this->ok) {
            $available_courses = explode(',', $this->platform->getSetting('__learndash_available_courses'));
            $this->ok = in_array(strval($course->ID), $available_courses);
            if (!$this->ok) {
                $this->reason = 'Course not available';
            }
        }
        if ($this->ok) {
            $now = time();
            $this->init_session();
            $user_login = $this->get_user_login();
            $user = $this->init_user($user_login);
        }
        if ($this->ok) {
            if ((strtotime($user->data->user_registered) >= $now) && learndash_new_user_email_enabled()) {
                wp_send_new_user_notifications($user->ID, 'admin');
            }
            if ($this->userResult->created >= $now) {
                $send_progress = $this->platform->getSetting('__learndash_send_progress');
                $progress = learndash_user_get_course_progress($user->ID, $course->ID);
                $score = lti_tool_learndash_get_score($send_progress, $user->ID, $course->ID, $progress);
                $courseuserresults = array($this->userResult);
                lti_tool_learndash_send_outcome($courseuserresults, $this->platform->getRecordId(), $user->ID,
                    $this->userResult->ltiUserId, $score);
            }
            $d = current_datetime();
            ld_update_course_access($user->ID, $course->ID);
            $this->login_user(get_current_blog_id(), $user, $user_login, $options);
            $this->redirectUrl = get_permalink($course->ID);
        }

        $lti_tool_session['resourcelinkpk'] = $this->resourceLink->getRecordId();
        $lti_tool_session['userpk'] = $this->userResult->getRecordId();

        lti_tool_set_session();
    }

}
