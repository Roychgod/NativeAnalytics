<?php namespace ProcessWire;

$info = array(
    'title' => 'NativeAnalytics Dashboard',
    'summary' => 'Dashboard for the NativeAnalytics module.',
    'version' => 1023,
    'author' => 'Pyxios - Roych (www.pyxios.com)',
    'permission' => 'nativeanalytics-view',
    'icon' => 'area-chart',
    'requires' => array('NativeAnalytics'),
    'page' => array(
        'name' => 'native-analytics',
        'title' => 'Analytics',
    ),
);
