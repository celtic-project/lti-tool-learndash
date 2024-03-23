<?php
/*
 *  lti-tool-learndash - WordPress module to integrate LTI support with LearnDash
 *  Copyright (C) 2024  Stephen P Vickers
 *
 *  Author: stephen@spvsoftwareproducts.com
 */

if (!defined('ABSPATH')) {
    exit;
}

class LTI_Tool_LearnDash_Binary_Selector_Courses extends Learndash_Binary_Selector_Posts
{

    /**
     * Public constructor for class
     *
     * @param array $args Array of arguments for class.
     */
    public function __construct($args = array())
    {
        $this->selector_class = get_class($this);

        $defaults = array(
            'user_id' => 0,
            'post_type' => 'sfwd-courses',
            'html_title' => '<h3>' . esc_html_x('Registered LearnDash Courses', 'Registered LearnDash courses label',
                LTI_TOOL_LEARNDASH_PLUGIN_NAME) . '</h3>',
            'html_id' => 'lti_tool_learndash_courses',
            'html_class' => 'lti_tool_learndash_courses',
            'html_name' => 'lti_tool_learndash_courses',
            'suppress_filters' => true,
            'search_label_left' => sprintf(
                esc_html_x('Search All LearnDash %s', 'Search All LearnDash Courses Label', LTI_TOOL_LEARNDASH_PLUGIN_NAME),
                LearnDash_Custom_Label::get_label('courses')
            ),
            'search_label_right' => sprintf(
                esc_html_x('Search Registered LearnDash %s', 'Search Registered LearnDash Courses Label',
                    LTI_TOOL_LEARNDASH_PLUGIN_NAME), LearnDash_Custom_Label::get_label('courses')
            ),
        );

        $args = wp_parse_args($args, $defaults);

        parent::__construct($args);
    }

    function getCourses()
    {
        $courses = array();
        $query = new WP_Query($this->args);
        if ($query->have_posts()) {
            $courses = $query->posts;
        }

        return $courses;
    }
}
