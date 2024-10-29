<?php

/**
 * Definition of the Notification::create method with all its parameters
 */

/**
 * 
 */
class Notifications {
    public static function create(
        $eventType,
        $pageTitle,
        $agent,
        // TODO: Some links are different between whether the notification is bundled
        // or not; there could be some definition inside presentation to separate
        // "bundled" and "regular" links (?)
        $presentation,
        $links, // Primary link is the first in the array
        $bundle
    ) {
        /* ... */
    }
}
