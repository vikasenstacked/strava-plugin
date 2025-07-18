<?php
/**
 * Plugin Loader Class
 * File: includes/class-loader.php
 */

class Strava_Coaching_Loader {
    
    /**
     * Array of actions registered with WordPress
     */
    protected $actions;
    
    /**
     * Array of filters registered with WordPress
     */
    protected $filters;
    
    /**
     * Array of shortcodes registered with WordPress
     */
    protected $shortcodes;
    
    /**
     * Initialize the collections used to maintain actions, filters, and shortcodes
     */
    public function __construct() {
        $this->actions = array();
        $this->filters = array();
        $this->shortcodes = array();
    }
    
    /**
     * Add a new action to the collection
     */
    public function add_action($hook, $component, $callback, $priority = 10, $accepted_args = 1) {
        $this->actions = $this->add($this->actions, $hook, $component, $callback, $priority, $accepted_args);
    }
    
    /**
     * Add a new filter to the collection
     */
    public function add_filter($hook, $component, $callback, $priority = 10, $accepted_args = 1) {
        $this->filters = $this->add($this->filters, $hook, $component, $callback, $priority, $accepted_args);
    }
    
    /**
     * Add a new shortcode to the collection
     */
    public function add_shortcode($tag, $component, $callback) {
        $this->shortcodes[] = array(
            'tag' => $tag,
            'component' => $component,
            'callback' => $callback
        );
    }
    
    /**
     * Utility function for adding hooks
     */
    private function add($hooks, $hook, $component, $callback, $priority, $accepted_args) {
        $hooks[] = array(
            'hook' => $hook,
            'component' => $component,
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args
        );
        
        return $hooks;
    }
    
    /**
     * Register all actions, filters, and shortcodes with WordPress
     */
    public function run() {
        // Register actions
        foreach ($this->actions as $hook) {
            add_action(
                $hook['hook'],
                array($hook['component'], $hook['callback']),
                $hook['priority'],
                $hook['accepted_args']
            );
        }
        
        // Register filters
        foreach ($this->filters as $hook) {
            add_filter(
                $hook['hook'],
                array($hook['component'], $hook['callback']),
                $hook['priority'],
                $hook['accepted_args']
            );
        }
        
        // Register shortcodes
        foreach ($this->shortcodes as $shortcode) {
            add_shortcode(
                $shortcode['tag'],
                array($shortcode['component'], $shortcode['callback'])
            );
        }
    }
}