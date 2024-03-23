<?php
/*
  Plugin Name: LTI Connector for LearnDash
  Description: This plugin allows LearnDash to be integrated with on-line courses using the 1EdTech Learning Tools Interoperability (LTI) specification.
  Version: 1.0.1
  Requires at least: 5.0
  Requires PHP: 7.0
  Author: Stephen P Vickers
  License: GPL3
 */

/*
 *  lti-tool-learndash - WordPress module to integrate LTI support with LearnDash
 *  Copyright (C) 2024  Stephen P Vickers
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License along
 *  with this program; if not, write to the Free Software Foundation, Inc.,
 *  51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 *  Author: stephen@spvsoftwareproducts.com
 */

use ceLTIc\LTI\Tool;
use ceLTIc\LTI\Platform;
use ceLTIc\LTI\UserResult;
use ceLTIc\LTI\ResourceLink;
use ceLTIc\LTI\Service;
use ceLTIc\LTI\Outcome;
use ceLTIc\LTI\DataConnector\DataConnector;
use ceLTIc\LTI\Util;
use ceLTIc\LTI\Enum\ServiceAction;

// Prevent loading this file directly
defined('ABSPATH') || exit;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

if (class_exists('ceLTIc\LTI\Platform')) {
    require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'LearnDashTool.php');
}

/**
 * Current plugin name.
 */
define('LTI_TOOL_LEARNDASH_PLUGIN_NAME', 'lti-tool-learndash');

/**
 * Check dependent plugins are activated when WordPress is loaded.
 */
function lti_tool_learndash_once_wp_loaded()
{
    if (!is_plugin_active('sfwd-lms/sfwd_lms.php') || !is_plugin_active('lti-tool/lti-tool.php')) {
        add_action('all_admin_notices', 'lti_tool_learndash_show_note_errors_activated');
        deactivate_plugins(plugin_basename(__FILE__));
        if (isset($_GET['activate'])) {
            unset($_GET['activate']);
        }
    }
    add_action('admin_enqueue_scripts', 'lti_tool_learndash_config_platform_css');
}

function lti_tool_learndash_show_note_errors_activated()
{
    $html = '    <div class="notice notice-error">' . "\n" .
        '      <p>The LTI Connector for LearnDash plugin requires both the <em>LearnDash LMS</em> and <em>LTI Tool</em> plugins to be installed and activated first.</p>' . "\n" .
        '    </div>' . "\n";
    $allowed = array('div' => array('class' => true), 'p' => array(), 'em' => array());
    echo(wp_kses($html, $allowed));
}

function lti_tool_learndash_config_platform_css()
{
    wp_enqueue_script(
        'learndash-admin-binary-selector-script',
        LEARNDASH_LMS_PLUGIN_URL . 'assets/js/learndash-admin-binary-selector' . learndash_min_asset() . '.js', array('jquery'),
        LEARNDASH_SCRIPT_VERSION_TOKEN, true
    );

    wp_enqueue_script(
        'sfwd-module-script', LEARNDASH_LMS_PLUGIN_URL . 'assets/js/sfwd_module' . learndash_min_asset() . '.js', array('jquery'),
        LEARNDASH_SCRIPT_VERSION_TOKEN, true
    );

    $filepath = SFWD_LMS::get_template('learndash_pager.js', null, null, true);
    if (!empty($filepath)) {
        wp_enqueue_script('learndash_pager_js', learndash_template_url_from_path($filepath), array('jquery'),
            LEARNDASH_SCRIPT_VERSION_TOKEN, true);
    }

    wp_enqueue_style(
        'learndash-admin-binary-selector-style',
        LEARNDASH_LMS_PLUGIN_URL . 'assets/css/learndash-admin-binary-selector' . learndash_min_asset() . '.css', array(),
        LEARNDASH_SCRIPT_VERSION_TOKEN
    );
    wp_style_add_data('learndash-admin-binary-selector-style', 'rtl', 'replace');
}

add_action('wp_loaded', 'lti_tool_learndash_once_wp_loaded');

/**
 * Check for requests being sent to this plugin.
 */
function lti_tool_learndash_parse_request()
{
    if (isset($_GET[LTI_TOOL_LEARNDASH_PLUGIN_NAME])) {
        if (isset($_GET['icon'])) {
            wp_redirect(plugins_url('images/learndash.jpg', __FILE__));
            exit;
        }
    }
}

add_action('parse_request', 'lti_tool_learndash_parse_request');

/**
 * Override Tool instance to be used by LTI Tool plugin.
 *
 * @param Tool $tool
 * @param ceLTIc\LTI\DataConnector\DataConnector $db_connector
 *
 * @return Tool
 */
function lti_tool_learndash_lti_tool($tool, $db_connector)
{
    return new LTI_Tool_LearnDash($db_connector);
}

add_filter('lti_tool_tool', 'lti_tool_learndash_lti_tool', 10, 2);

/**
 * Hide unnecessary options from LTI Tool options page.
 *
 * @param array $hide_options
 *
 * @return array
 */
function lti_tool_learndash_hide_options($hide_options)
{
    global $lti_tool_data_connector;

    $hide_options = array();
    $hide_options['uninstallblogs'] = '0';
    $hide_options['adduser'] = '1';
    $hide_options['mysites'] = '1';
    $hide_options['scope'] = LTI_Tool_WP_User::ID_SCOPE_EMAIL;
    $hide_options['saveemail'] = '1';
    $hide_options['homepage'] = '';

    return $hide_options;
}

add_filter('lti_tool_hide_options', 'lti_tool_learndash_hide_options', 10, 1);

/**
 * Add options to LTI Tool options page
 */
function lti_tool_learndash_init_options()
{
    add_settings_field(
        'learndash_send_progress', __('Default progress to send', LTI_TOOL_LEARNDASH_PLUGIN_NAME),
        'lti_tool_learndash_send_progress_callback', 'lti_tool_options_admin', 'lti_tool_options_general_section'
    );
}

function lti_tool_learndash_send_progress_callback()
{
    $name = 'learndash_send_progress';
    $options = lti_tool_get_options();
    $current = isset($options[$name]) ? $options[$name] : '';
    printf('<select name="lti_tool_options[%s]" id="%s">', esc_attr($name), esc_attr($name));
    echo "\n";
    $choices = array(__('None', LTI_TOOL_LEARNDASH_PLUGIN_NAME) => 'none', __('Completion of course only',
            LTI_TOOL_LEARNDASH_PLUGIN_NAME) => 'course',
        __('Completion of lessons only', LTI_TOOL_LEARNDASH_PLUGIN_NAME) => 'lesson', __('Completion of topics only',
            LTI_TOOL_LEARNDASH_PLUGIN_NAME) => 'topic',
        __('Completion of both topics and lessons', LTI_TOOL_LEARNDASH_PLUGIN_NAME) => 'topiclesson');
    foreach ($choices as $key => $value) {
        $selected = ($value === $current) ? ' selected' : '';
        printf('  <option value="%s"%s>%s</option>', esc_attr($value), esc_attr($selected), esc_html($key));
        echo "\n";
    }
    echo ("</select>\n");
}

add_action('lti_tool_init_options', 'lti_tool_learndash_init_options');

/**
 * Add available courses and progress reporting options to LTI Platform configuration page.
 *
 * @param array $html
 * @param ceLTIc\LTI\Platform $platform
 *
 * @return array
 */
function lti_tool_learndash_config_platform($html, $platform)
{
    $choices = array(__('None', LTI_TOOL_LEARNDASH_PLUGIN_NAME) => 'none', __('Completion of course only',
            LTI_TOOL_LEARNDASH_PLUGIN_NAME) => 'course',
        __('Completion of lessons only', LTI_TOOL_LEARNDASH_PLUGIN_NAME) => 'lesson', __('Completion of topics only',
            LTI_TOOL_LEARNDASH_PLUGIN_NAME) => 'topic',
        __('Completion of both topics and lessons', LTI_TOOL_LEARNDASH_PLUGIN_NAME) => 'topiclesson');
    $selected = function($value, $current) {
        if ($value === $current) {
            return ' selected';
        } else {
            return '';
        }
    };
    $html['general'] = '        <tr>' . "\n" .
        '          <th scope="row">' . "\n" .
        '            <label for="lti_tool_learndash_send_progress">Progress to be sent</label>' . "\n" .
        '          </th>' . "\n" .
        '          <td>' . "\n" .
        '            <fieldset>' . "\n" .
        '                <select name="lti_tool_learndash_send_progress" id="lti_tool_learndash_send_progress">' . "\n";
    $options = lti_tool_get_options();
    $current = $platform->getSetting('__learndash_send_progress', $options['learndash_send_progress']);
    foreach ($choices as $key => $value) {
        $html['general'] .= '                  <option value="' . $value . '"' . $selected($value, $current) . '>' . $key . '</option>' . "\n";
    }
    $html['general'] .= '                </select>' . "\n" .
        '            </fieldset>' . "\n" .
        '          </td>' . "\n" .
        '        </tr>' . "\n";

    require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'LTI_Tool_LearnDash_Binary_Selector_Courses.php');

    $courseids = explode(',', $platform->getSetting('__learndash_available_courses'));
    $courses = array();
    foreach ($courseids as $courseid) {
        $courses[] = absint($courseid);
    }
    ob_start();
    $course_selector = new LTI_Tool_LearnDash_Binary_Selector_Courses(array('selected_ids' => $courses));
    $course_selector->show();
    $selector = ob_get_contents();
    ob_end_clean();
    $html['courses'] = $selector;

    return $html;
}

add_filter('lti_tool_config_platform', 'lti_tool_learndash_config_platform', 10, 2);

/**
 * Ensure added platform configuration options are saved.
 *
 * @param ceLTIc\LTI\Platform $platform
 * @param array $options
 * @param array $data
 *
 * @return ceLTIc\LTI\Platform
 */
function lti_tool_learndash_save_platform($platform, $options, $data)
{
    if (isset($data['lti_tool_learndash_courses'])) {
        if ((isset($data['lti_tool_learndash_courses-changed'])) && ($data['lti_tool_learndash_courses-changed'] === '1')) {
            if ((isset($data['lti_tool_learndash_courses-nonce'])) && (!empty($data['lti_tool_learndash_courses-nonce']))) {
                if (wp_verify_nonce(sanitize_text_field(wp_unslash($data['lti_tool_learndash_courses-nonce'])),
                        'lti_tool_learndash_courses')) {
                    $courses = implode(',', (array) json_decode(wp_unslash($data['lti_tool_learndash_courses'])));
                    $platform->setSetting('__learndash_available_courses', $courses);
                }
            }
        }
    }
    $progress = null;
    if (isset($data['lti_tool_learndash_send_progress'])) {
        $progress = sanitize_text_field($data['lti_tool_learndash_send_progress']);
        if (empty($progress)) {
            $progress = null;
        }
    } else if (isset($options['learndash_send_progress'])) {
        $progress = $options['learndash_send_progress'];
    }
    $platform->setSetting('__learndash_send_progress', $progress);

    return $platform;
}

add_filter('lti_tool_save_platform', 'lti_tool_learndash_save_platform', 10, 3);

/**
 * Restrict user scopes offered for platform configurations.
 *
 * @param array $scopes
 *
 * @return array
 */
function lti_tool_learndash_id_scopes($scopes)
{
    $learndash_scopes = array();
    $learndash_scopes[LTI_Tool_WP_User::ID_SCOPE_EMAIL] = $scopes[LTI_Tool_WP_User::ID_SCOPE_EMAIL];

    return $learndash_scopes;
}

add_filter('lti_tool_id_scopes', 'lti_tool_learndash_id_scopes', 10, 1);

/**
 * Override Canvas XML configuration output.
 *
 * @param DOMDocument $dom
 *
 * @return DOMDocument
 */
function lti_tool_learndash_lti_configure_xml($dom)
{
    $dom->getElementsByTagNameNS('http://www.imsglobal.org/xsd/imsbasiclti_v1p0', 'title')[0]->childNodes[0]->nodeValue = 'LearnDash LMS';
    $dom->getElementsByTagNameNS('http://www.imsglobal.org/xsd/imsbasiclti_v1p0', 'description')[0]->childNodes[0]->nodeValue = 'Access LearnDash LMS using LTI';
    $dom->getElementsByTagNameNS('http://www.imsglobal.org/xsd/imsbasiclti_v1p0', 'icon')[0]->childNodes[0]->nodeValue = get_bloginfo('url') . '/?' . LTI_TOOL_LEARNDASH_PLUGIN_NAME . '&icon';

    return $dom;
}

add_filter('lti_tool_configure_xml', 'lti_tool_learndash_lti_configure_xml', 10, 1);

/**
 * Override Canvas JSON configuration output.
 *
 * @param object $configuration
 *
 * @return object
 */
function lti_tool_learndash_lti_configure_json($configuration)
{
    $configuration->title = 'LearnDash LMS';
    $configuration->description = 'Access LearnDash LMS using LTI';
    $configuration->extensions[0]->settings->icon_url = get_bloginfo('url') . '/?' . LTI_TOOL_LEARNDASH_PLUGIN_NAME . '&icon';
    if (!isset($configuration->scopes[Service\Score::$SCOPE])) {
        $configuration->scopes[] = Service\Score::$SCOPE;
    }

    return $configuration;
}

add_filter('lti_tool_configure_json', 'lti_tool_learndash_lti_configure_json', 10, 1);

/**
 * Calculate the progress value.
 *
 * @param string $send_progress  Progress reporting option
 * @param int $user_id  User ID
 * @param int $course_id  Course ID
 * @param array $progress  Progress data
 *
 * @return float|null
 */
function lti_tool_learndash_get_score($send_progress, $user_id, $course_id, $progress)
{
    $score = null;
    switch ($send_progress) {
        case 'topic':
            $total = $progress['total'] - count($progress['lessons']);
            $completed = learndash_course_get_completed_steps($user_id, $course_id, array('topics' => $progress['topics']));
            break;
        case 'lesson':
            $total = count($progress['lessons']);
            $completed = learndash_course_get_completed_steps($user_id, $course_id, array('lessons' => $progress['lessons']));
            break;
        case 'topiclesson':
            $total = $progress['total'];
            $completed = learndash_course_get_completed_steps($user_id, $course_id, $progress);
            break;
        case 'course':
            $total = $progress['total'];
            $completed = $progress['completed'];
            break;
        default:
            $total = 0;
            break;
    }
    if ($total > 0) {
        if ($completed >= $total) {
            $score = 1;
        } else {
            $score = $completed / $total;
        }
    }

    return $score;
}

/**
 * Get LTI User Result objects for a user.
 *
 * @global wpdb $wpdb  WordPress database object
 * @global DataConnector $lti_tool_data_connector  LTI data connector object
 *
 * @param int $platform_id  Platform ID
 * @param string $lti_user_id  LTI user ID
 * @param string $slug  Course slug
 *
 * @return array
 */
function lti_tool_learndash_get_course_user_results($platform_id, $lti_user_id, $slug)
{
    global $wpdb, $lti_tool_data_connector;

    $course_user_results = array();
    $sql = 'SELECT u.user_result_pk, u.resource_link_pk ' .
        "FROM {$wpdb->prefix}" . DataConnector::USER_RESULT_TABLE_NAME . ' AS u ' .
        "INNER JOIN {$wpdb->prefix}" . DataConnector::RESOURCE_LINK_TABLE_NAME . ' AS r ON (u.resource_link_pk = r.resource_link_pk) ' .
        "LEFT OUTER JOIN {$wpdb->prefix}" . DataConnector::CONTEXT_TABLE_NAME . ' AS c ON (r.context_pk = c.context_pk) ' .
        'WHERE (u.lti_user_id = %s) AND ((c.consumer_pk = %d) OR (r.consumer_pk = %d))';
    $userresults = $wpdb->get_results($wpdb->prepare($sql, $lti_user_id, $platform_id, $platform_id));
    foreach ($userresults as $userresult) {
        $resource_link = ResourceLink::fromRecordId(intval($userresult->resource_link_pk), $lti_tool_data_connector);
        if ($resource_link->getSetting('custom_course') === $slug) {
            $user_result = UserResult::fromRecordId($userresult->user_result_pk, $lti_tool_data_connector);
            $user_result->setResourceLink($resource_link);
            $course_user_results[] = $user_result;
        }
    }

    return $course_user_results;
}

/**
 * Send outcome to LTI platform.
 *
 * @global wpdb $wpdb  WordPress database object
 * @global DataConnector $lti_tool_data_connector  LTI data connector object
 *
 * @param array $courseuserresults  User Result objects
 * @param int $platform_id  Platform ID
 * @param int $user_id  User ID
 * @param string $lti_user_id  LTI user ID
 * @param float $score  User progress outcome value
 */
function lti_tool_learndash_send_outcome($courseuserresults, $platform_id, $user_id, $lti_user_id, $score)
{
    global $wpdb, $lti_tool_data_connector;

    $outcome = new Outcome();
    if ($score >= 1) {
        $outcome->activityProgress = 'Completed';
    } else {
        $outcome->activityProgress = 'InProgress';
    }
    $outcome->setValue($score);
    $outcome->setPointsPossible(1.0);
    $outcome->gradingProgress = 'FullyGraded';
    if (function_exists('lti_tool_use_lti_library_v5') && lti_tool_use_lti_library_v5()) {
        $service_action = ServiceAction::Write;
    } else {
        $service_action = ResourceLink::EXT_WRITE;
    }
    foreach ($courseuserresults as $courseuserresult) {
        if (!$courseuserresult->getResourceLink()->doOutcomesService($service_action, $outcome, $courseuserresult)) {
            Util::logError(LTI_TOOL_LEARNDASH_PLUGIN_NAME . ": error sending progress of '{$score}' for user ID '{$user_id}'");
        }
    }
}

/**
 * Update LTI platform with user progress.
 *
 * @global DataConnector $lti_tool_data_connector  LTI data connector object
 * @global array $lti_tool_learndash_progress  LearnDash course progress
 * @global float $lti_tool_learndash_score  User progress
 *
 * @param type $args
 */
function lti_tool_learndash_update_activity($args)
{
    global $lti_tool_data_connector, $lti_tool_learndash_progress, $lti_tool_learndash_score;

    if (($args['activity_status'] !== '')) {
        $usermeta = get_user_meta($args['user_id']);
        if (is_array($usermeta) && !empty($usermeta['lti_tool_platform_key']) && !empty($usermeta['lti_tool_user_id'])) {
            $platform = Platform::fromConsumerKey(reset($usermeta['lti_tool_platform_key']), $lti_tool_data_connector);
            $send_progress = $platform->getSetting('__learndash_send_progress');
            if (strpos($send_progress, $args['activity_type']) !== false) {
                $total = 0;
                if (!isset($lti_tool_learndash_progress)) {
                    $lti_tool_learndash_progress = learndash_user_get_course_progress($args['user_id'], $args['course_id']);
                }
                switch ($args['activity_type']) {
                    case 'topic':
                        foreach ($lti_tool_learndash_progress['topics'] as $id => $topics) {
                            if (array_key_exists($args['post_id'], $topics)) {
                                $lti_tool_learndash_progress['topics'][$id][$args['post_id']] = ($args['activity_status']) ? 1 : 0;
                                break;
                            }
                        }
                        break;
                    case 'lesson':
                        if (isset($lti_tool_learndash_progress['lessons'][$args['post_id']])) {
                            $lti_tool_learndash_progress['lessons'][$args['post_id']] = ($args['activity_status']) ? 1 : 0;
                        }
                        break;
                    case 'course':
                        if ($args['activity_status']) {
                            $lti_tool_learndash_progress['total'] = 1;
                            $lti_tool_learndash_progress['completed'] = 1;
                        } elseif (empty($args['activity_meta'])) {
                            $lti_tool_learndash_progress['total'] = 1;
                            $lti_tool_learndash_progress['completed'] = 0;
                        } else {
                            $lti_tool_learndash_progress['total'] = 0;
                            $lti_tool_learndash_progress['completed'] = 0;
                        }
                        break;
                }
                if ($lti_tool_learndash_progress['total'] > 0) {
                    $lti_tool_learndash_score = lti_tool_learndash_get_score($send_progress, $args['user_id'], $args['course_id'],
                        $lti_tool_learndash_progress);
                }
            }
        }
        if (($args['activity_type'] === 'course') && isset($lti_tool_learndash_score)) {
            $course = get_post($args['course_id']);
            $courseuserresults = lti_tool_learndash_get_course_user_results($platform->getRecordId(),
                reset($usermeta['lti_tool_user_id']), $course->post_name);
            lti_tool_learndash_send_outcome($courseuserresults, $platform->getRecordId(), $args['user_id'],
                reset($usermeta['lti_tool_user_id']), $lti_tool_learndash_score);
        }
    }
}

add_filter('learndash_update_user_activity_args', 'lti_tool_learndash_update_activity', 10, 1);

/**
 * Override new user email for LTI users.
 *
 * @global type $lti_tool_data_connector
 *
 * @param array $wp_new_user_notification_email
 * @param WP_User $user
 * @param string $blogname
 *
 * @return string
 */
function lti_tool_learndash_notification($wp_new_user_notification_email, $user, $blogname)
{
    global $lti_tool_data_connector;

    $usermeta = get_user_meta($user->ID);
    if (!empty($usermeta['lti_tool_platform_key'])) {
        $platform = Platform::fromConsumerKey(reset($usermeta['lti_tool_platform_key']), $lti_tool_data_connector);
        $wp_new_user_notification_email['subject'] = '[%s] New LTI User';
        $wp_new_user_notification_email['message'] = 'A new user has connected via LTI and been registered in WordPress.' . "\n\n" .
            'LTI platform: ' . $platform->name . "\n\n" .
            'Name: ' . $user->display_name . "\n" .
            'Username: ' . $user->user_login . "\n" .
            'Email: ' . $user->user_email;
    }

    return $wp_new_user_notification_email;
}

add_filter('wp_new_user_notification_email_admin', 'lti_tool_learndash_notification', 10, 3);
