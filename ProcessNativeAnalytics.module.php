<?php namespace ProcessWire;

class ProcessNativeAnalytics extends Process {

    public static function getModuleInfo() {
        return [
            'title' => 'NativeAnalytics Dashboard',
            'summary' => 'Dashboard for the NativeAnalytics module.',
            'version' => '1.0.19',
            'author' => 'Pyxios - Roych (www.pyxios.com)',
            'permission' => 'nativeanalytics-view',
            'icon' => 'area-chart',
            'requires' => ['NativeAnalytics'],
            'page' => [
                'name' => 'native-analytics',
                'title' => 'Analytics',
            ],
        ];
    }

    public function uninstall() {
        $session = $this->wire('session');
        $modules = $this->wire('modules');

        try {
            $mode = (string) $session->getFor('NativeAnalytics', 'uninstall_mode');
            if($mode !== 'main-direct' && $mode !== 'dashboard-direct' && $modules->isInstalled('NativeAnalytics')) {
                $session->setFor('NativeAnalytics', 'uninstall_mode', 'dashboard-direct');
                try {
                    $modules->uninstall('NativeAnalytics');
                } catch(\Throwable $e) {
                    $this->wire('log')->save('native-analytics', 'Cascade uninstall from dashboard failed: ' . $e->getMessage());
                }
            }
            $this->cleanupAdminPages();
            $this->cleanupModuleRegistry(['ProcessNativeAnalytics', 'NativeAnalytics']);
            try {
                $this->wire('modules')->refresh();
            } catch(\Throwable $e) {
                $this->wire('log')->save('native-analytics', 'Modules refresh after dashboard uninstall failed: ' . $e->getMessage());
            }
        } finally {
            if((string) $session->getFor('NativeAnalytics', 'uninstall_mode') === 'dashboard-direct') {
                $session->removeFor('NativeAnalytics', 'uninstall_mode');
            }
        }
    }

    protected function cleanupAdminPages() {
        try {
            $pages = $this->wire('pages');
            $adminRoot = (int) $this->wire('config')->adminRootPageID;
            $candidates = [];

            foreach(['native-analytics', 'analytics'] as $name) {
                try {
                    $found = $pages->find('template=admin, include=all, name=' . $name . ', sort=-id');
                    foreach($found as $item) $candidates[$item->id] = $item;
                } catch(\Throwable $e) {
                }
            }

            if($adminRoot) {
                try {
                    $admin = $pages->get($adminRoot);
                    if($admin && $admin->id) {
                        foreach($admin->children('include=all, sort=-id') as $item) {
                            $processName = '';
                            try {
                                $proc = $item->get('process');
                                if(is_object($proc)) {
                                    if(isset($proc->className)) $processName = (string) $proc->className;
                                    elseif(isset($proc->name)) $processName = (string) $proc->name;
                                    else $processName = get_class($proc);
                                } else {
                                    $processName = (string) $proc;
                                }
                            } catch(\Throwable $e) {
                                $processName = '';
                            }
                            if($item->name === 'native-analytics' || ($item->name === 'analytics' && stripos($processName, 'ProcessNativeAnalytics') !== false) || stripos($processName, 'ProcessNativeAnalytics') !== false) {
                                $candidates[$item->id] = $item;
                            }
                        }
                    }
                } catch(\Throwable $e) {
                }
            }

            foreach($candidates as $item) {
                try {
                    foreach($item->children('include=all, sort=-id') as $child) {
                        $child->delete(true);
                    }
                } catch(\Throwable $e) {
                }
                try {
                    if(method_exists($item, 'setOutputFormatting')) $item->setOutputFormatting(false);
                    if(defined('ProcessWire\Page::statusSystem') && isset($item->status)) {
                        $item->status = $item->status & ~Page::statusSystem;
                    }
                    $item->delete(true);
                } catch(\Throwable $e) {
                    $this->wire('log')->save('native-analytics', 'Dashboard admin page delete failed for #' . (int) $item->id . ': ' . $e->getMessage());
                }
            }
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'Dashboard admin page cleanup failed: ' . $e->getMessage());
        }
    }

    protected function cleanupModuleRegistry(array $classes) {
        try {
            $db = $this->wire('database');
            $prefix = (string) ($this->wire('config')->dbTablePrefix ?? '');
            $table = $prefix . 'modules';
            foreach($classes as $class) {
                $stmt = $db->prepare('DELETE FROM `' . $table . '` WHERE `class` = :class');
                $stmt->execute([':class' => $class]);
            }
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'Module registry cleanup failed: ' . $e->getMessage());
        }
    }

public function ___execute() {
    /** @var NativeAnalytics $analytics */
    $analytics = $this->modules->get('NativeAnalytics');
    if(!$analytics) return '<p>NativeAnalytics is not available.</p>';

    if($this->user->hasPermission('nativeanalytics-manage') && $this->input->post('pwna_action')) {
        $this->handlePostActions($analytics);
    }

    $rangeMeta = $this->getRangeMeta($analytics);
    $rangeSpec = $rangeMeta['rangeSpec'];
    $pageId = (int) $this->input->get('page_id');
    $template = $this->sanitizer->name($this->input->get('template'));
    $activeTab = $this->getActiveTab();
    $filters = [];
    if($pageId > 0) $filters['page_id'] = $pageId;
    if($template !== '') $filters['template'] = $template;

    $summary = $analytics->getSummary($rangeSpec, $filters);
    $quality = $analytics->getSessionQuality($rangeSpec, $filters);
    $current = $analytics->getCurrentVisitorsSummary((int) $analytics->realtimeWindowMinutes, $filters);
    $summary404 = $analytics->get404Summary($rangeSpec);
    $series = $analytics->getDailySeries($rangeSpec, $filters);
    $hourlySeries = $analytics->getHourlySeries($rangeMeta['rangeSpec']['end_date'] ?? date('Y-m-d'), $filters);
    $pages = $analytics->getTopPages($rangeSpec, 15, $filters);
    $landing = $analytics->getTopLandingPages($rangeSpec, 10, $filters);
    $exits = $analytics->getTopExitPages($rangeSpec, 10, $filters);
    $currentVisitors = $analytics->getCurrentVisitors((int) $analytics->realtimeWindowMinutes, 25, $filters);
    $top404 = $analytics->getTop404Paths($rangeSpec, 12);
    $referrers = $analytics->getTopReferrers($rangeSpec, 10, $filters);
    $searchTerms = $analytics->getTopSearchTerms($rangeSpec, 10, $filters);
    $campaigns = $analytics->getTopCampaigns($rangeSpec, 10, $filters);
    $browsers = $analytics->getBreakdown('browser', $rangeSpec, 10, $filters);
    $devices = $analytics->getBreakdown('device_type', $rangeSpec, 10, $filters);
    $os = $analytics->getBreakdown('os', $rangeSpec, 10, $filters);
    $templates = $analytics->getBreakdown('template', $rangeSpec, 20, []);
    $health = $analytics->getHealthSnapshot();
    $compareMeta = $this->getCompareMeta($analytics, $rangeMeta);

    $compareSummary = [];
    $compareQuality = [];
    $compareSummary404 = [];
    $compareSeries = [];
    if($compareMeta['enabled']) {
        $compareSummary = $analytics->getSummary($compareMeta['rangeSpec'], $filters);
        $compareQuality = $analytics->getSessionQuality($compareMeta['rangeSpec'], $filters);
        $compareSummary404 = $analytics->get404Summary($compareMeta['rangeSpec']);
        $compareSeries = $analytics->getDailySeries($compareMeta['rangeSpec'], $filters);
    }

    $eventSummary = $analytics->getEventSummary($rangeSpec, $filters);
    $eventFormSummary = $analytics->getEventSummary($rangeSpec, $filters, 'form');
    $eventDownloadSummary = $analytics->getEventSummary($rangeSpec, $filters, 'download');
    $eventContactSummary = $analytics->getEventSummary($rangeSpec, $filters, 'contact');
    $eventNavigationSummary = $analytics->getEventSummary($rangeSpec, $filters, 'navigation');
    $eventSeries = $analytics->getEventDailySeries($rangeSpec, $filters);
    $topEvents = $analytics->getTopEvents($rangeSpec, 12, $filters);
    $topDownloads = $analytics->getTopEvents($rangeSpec, 10, $filters, 'download');
    $topForms = $analytics->getTopEvents($rangeSpec, 10, $filters, 'form');
    $topContacts = $analytics->getTopEvents($rangeSpec, 10, $filters, 'contact');
    $topEventTargets = $analytics->getTopEventTargets($rangeSpec, 12, $filters);

    $this->config->styles->add($analytics->getAssetUrl('assets/admin.css') . '?v=' . rawurlencode(NativeAnalytics::VERSION));
    $wireTabs = null;
    try {
        $wireTabs = $this->modules->get('JqueryWireTabs');
    } catch(\Throwable $e) {
        $wireTabs = null;
    }
    $this->headline($this->_('Analytics'));

    $out = '';
    $out .= $this->renderInlineCssFallback();
    $out .= $this->renderBrandHeader($analytics);
    $engagementView = $this->getEngagementView();

    $overviewContent = $this->renderToolbar($rangeMeta, $pageId, $template, $templates, 'overview');
    $overviewContent .= $this->renderCards($summary, $current, $summary404, $quality);
    $overviewContent .= '<div class="pwna-grid-2">';
    $overviewContent .= '<div class="pwna-panel"><h2>Traffic trend</h2><p class="pwna-note">Daily totals for the selected period.</p>' . $analytics->renderLineChart($series, 'views', 'Traffic trend by day') . '</div>';
    $overviewContent .= '<div class="pwna-panel"><h2>Traffic by hour</h2><p class="pwna-note">Hover a point to see the hour, views, uniques and sessions for the last day in the selected range.</p>' . $analytics->renderLineChart($hourlySeries, 'views', 'Traffic by hour for selected day') . '</div>';
    $overviewContent .= '</div>';
    $overviewContent .= '<div class="pwna-grid-2">';
    $overviewContent .= $this->renderSimpleTable('Top pages', ['Page', 'Views', 'Uniques', 'Sessions'], $this->mapTopPages($pages));
    $overviewContent .= $this->renderCurrentVisitorsPanel($analytics, $currentVisitors, (int) $analytics->realtimeWindowMinutes);
    $overviewContent .= '</div>';
    $overviewContent .= '<div class="pwna-grid-2">';
    $overviewContent .= $this->renderSimpleTable('Top landing pages', ['Landing page', 'Sessions started'], $this->mapSessionPageRows($landing));
    $overviewContent .= $this->renderSimpleTable('Top exit pages', ['Exit page', 'Sessions ended'], $this->mapSessionPageRows($exits));
    $overviewContent .= '</div>';

    $compareContent = $this->renderCompareToolbar($rangeMeta, $compareMeta, $pageId, $template, $templates);
    $compareContent .= $this->renderComparePanel($analytics, $rangeMeta, $compareMeta, $summary, $compareSummary, $quality, $compareQuality, $summary404, $compareSummary404, $series, $compareSeries, $pageId, $template, $templates);

    $engagementMetricsContent = $this->renderEventCards($eventSummary, $eventFormSummary, $eventDownloadSummary, $eventContactSummary, $eventNavigationSummary);
    $engagementMetricsContent .= '<div class="pwna-panel"><h2>Engagement trend</h2><p class="pwna-note">Tracked actions over time for the selected period.</p>' . $analytics->renderLineChart($eventSeries, 'views', 'Tracked actions by day') . '</div>';
    $engagementMetricsContent .= '<div class="pwna-grid-2">';
    $engagementMetricsContent .= $this->renderSimpleTable('Top tracked actions', ['Action', 'Events', 'Unique visitors', 'Sessions'], $this->mapEventRows($topEvents));
    $engagementMetricsContent .= $this->renderSimpleTable('Top action targets', ['Target', 'Group', 'Events', 'Sessions'], $this->mapEventTargetRows($topEventTargets));
    $engagementMetricsContent .= '</div>';
    $engagementMetricsContent .= '<div class="pwna-grid-3">';
    $engagementMetricsContent .= $this->renderSimpleTable('Top form submits', ['Action', 'Events', 'Sessions'], $this->mapEventRowsCompact($topForms));
    $engagementMetricsContent .= $this->renderSimpleTable('Top downloads', ['Action', 'Events', 'Sessions'], $this->mapEventRowsCompact($topDownloads));
    $engagementMetricsContent .= $this->renderSimpleTable('Top contact clicks', ['Action', 'Events', 'Sessions'], $this->mapEventRowsCompact($topContacts));
    $engagementMetricsContent .= '</div>';

    $engagementContent = $this->renderToolbar($rangeMeta, $pageId, $template, $templates, 'engagement');
    $engagementContent .= $this->renderEngagementSubnav($engagementView);
    $engagementContent .= $this->renderEngagementPanels($engagementMetricsContent, $this->renderEventHelperPanel(), $engagementView);

    $sourcesContent = $this->renderToolbar($rangeMeta, $pageId, $template, $templates, 'sources');
    $sourcesContent .= '<div class="pwna-grid-2">';
    $sourcesContent .= $this->renderSimpleTable('Top referrers', ['Referrer', 'Views'], $this->mapGenericRows($referrers, 'referrer_host'));
    $sourcesContent .= $this->renderSimpleTable('Internal search terms', ['Search term', 'Views'], $this->mapGenericRows($searchTerms, 'search_term'));
    $sourcesContent .= '</div>';
    $sourcesContent .= '<div class="pwna-grid-2">';
    $sourcesContent .= $this->renderSimpleTable('Campaigns / UTM traffic', ['Campaign', 'Views', 'Uniques', 'Sessions'], $this->mapCampaignRows($campaigns));
    $sourcesContent .= $this->renderSimpleTable('404 pages', ['Missing path', 'Hits', 'Unique visitors'], $this->map404Rows($top404));
    $sourcesContent .= '</div>';

    $techContent = $this->renderToolbar($rangeMeta, $pageId, $template, $templates, 'tech');
    $techContent .= '<div class="pwna-grid-3">';
    $techContent .= $this->renderSimpleTable('Browsers', ['Browser', 'Views'], $this->mapGenericRows($browsers));
    $techContent .= $this->renderSimpleTable('Devices', ['Device', 'Views'], $this->mapGenericRows($devices));
    $techContent .= $this->renderSimpleTable('Operating systems', ['OS', 'Views'], $this->mapGenericRows($os));
    $techContent .= '</div>';
    if($this->user->hasPermission('nativeanalytics-manage')) {
        $techContent .= $this->renderManageActions();
        $techContent .= $this->renderHealthPanel($health);
    }

    if($wireTabs) {
        $out .= '<div id="pwna-wiretabs" class="pwna-wiretabs">';
        $out .= $wireTabs->render([
            'Overview' => $overviewContent,
            'Engagement' => $engagementContent,
            'Compare' => $compareContent,
            'Sources' => $sourcesContent,
            'System' => $techContent,
        ]);
        $out .= '</div>';
    } else {
        $out .= $this->renderTabNav($activeTab, $rangeMeta, $pageId, $template, $compareMeta['selected']);
        $out .= '<div class="pwna-tab-panels">';
        $out .= '<section class="pwna-tab-panel">' . (
            $activeTab === 'engagement' ? $engagementContent : (
            $activeTab === 'compare' ? $compareContent : (
            $activeTab === 'sources' ? $sourcesContent : (
            $activeTab === 'tech' ? $techContent : $overviewContent
        )))) . '</section>';
        $out .= '</div>';
    }

    $out .= $this->renderChartTooltipScript();
    $out .= $this->renderHelperToolsScript();
    $out .= $this->renderAutoRefreshScript($rangeMeta, (int) $analytics->realtimeWindowMinutes, $pageId, $template);
    if($wireTabs) $out .= $this->renderWireTabsScript($activeTab, $engagementView);
    return $out;
}

    public function ___executeExport() {
        /** @var NativeAnalytics $analytics */
        $analytics = $this->modules->get('NativeAnalytics');
        $rangeMeta = $this->getRangeMeta($analytics);
        $pageId = (int) $this->input->get('page_id');
        $template = $this->sanitizer->name($this->input->get('template'));
        $filters = [];
        if($pageId > 0) $filters['page_id'] = $pageId;
        if($template !== '') $filters['template'] = $template;

        $rows = $analytics->getTopPages($rangeMeta['rangeSpec'], 500, $filters);
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, ['range', $rangeMeta['label']], ',', '"', '\\');
        fputcsv($fp, ['page_id', 'title', 'template', 'path', 'views', 'uniques', 'sessions'], ',', '"', '\\');
        foreach($rows as $row) {
            fputcsv($fp, [$row['page_id'], $row['page_title'], $row['template'], $row['path'], $row['views'], $row['uniques'], $row['sessions']], ',', '"', '\\');
        }
        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);
        $this->sendDownloadResponse((string) $csv, 'text/csv; charset=utf-8', 'native-analytics-' . date('Ymd-His') . '.csv');
    }

    public function ___executePdf() {
        /** @var NativeAnalytics $analytics */
        $analytics = $this->modules->get('NativeAnalytics');
        $report = $this->buildReportData($analytics);
        $lines = $this->buildReportLines($report);
        $pdf = $this->buildSimplePdf($lines);
        $this->sendDownloadResponse($pdf, 'application/pdf', 'native-analytics-report-' . date('Ymd-His') . '.pdf');
    }

    public function ___executeDocx() {
        /** @var NativeAnalytics $analytics */
        $analytics = $this->modules->get('NativeAnalytics');
        if(!class_exists('ZipArchive')) {
            throw new WireException('ZipArchive is required for DOCX export.');
        }
        $report = $this->buildReportData($analytics);
        $lines = $this->buildReportLines($report);
        $docx = $this->buildSimpleDocx($lines);
        $this->sendDownloadResponse($docx, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'native-analytics-report-' . date('Ymd-His') . '.docx');
    }

    protected function sendDownloadResponse($content, $contentType, $filename) {
        if(function_exists('session_write_close')) @session_write_close();
        while(ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string) $filename) . '"');
        header('Content-Length: ' . strlen((string) $content));
        header('Cache-Control: private, must-revalidate');
        header('Pragma: public');
        header('X-Content-Type-Options: nosniff');
        echo $content;
        exit;
    }

protected function buildReportData(NativeAnalytics $analytics) {
    $rangeMeta = $this->getRangeMeta($analytics);
    $pageId = (int) $this->input->get('page_id');
    $template = $this->sanitizer->name($this->input->get('template'));
    $filters = [];
    if($pageId > 0) $filters['page_id'] = $pageId;
    if($template !== '') $filters['template'] = $template;
    $compareMeta = $this->getCompareMeta($analytics, $rangeMeta);
    $report = [
        'generated_at' => $analytics->formatDisplayDateTime(date('Y-m-d H:i:s')), 
        'range_label' => $rangeMeta['label'],
        'filters_label' => $this->buildFilterLabel($filters),
        'compare_meta' => $compareMeta,
        'summary' => $analytics->getSummary($rangeMeta['rangeSpec'], $filters),
        'quality' => $analytics->getSessionQuality($rangeMeta['rangeSpec'], $filters),
        'summary404' => $analytics->get404Summary($rangeMeta['rangeSpec']),
        'top_pages' => $analytics->getTopPages($rangeMeta['rangeSpec'], 10, $filters),
        'landing' => $analytics->getTopLandingPages($rangeMeta['rangeSpec'], 10, $filters),
        'exits' => $analytics->getTopExitPages($rangeMeta['rangeSpec'], 10, $filters),
        'referrers' => $analytics->getTopReferrers($rangeMeta['rangeSpec'], 10, $filters),
        'search_terms' => $analytics->getTopSearchTerms($rangeMeta['rangeSpec'], 10, $filters),
        'campaigns' => $analytics->getTopCampaigns($rangeMeta['rangeSpec'], 10, $filters),
        'paths404' => $analytics->getTop404Paths($rangeMeta['rangeSpec'], 10),
        'event_summary' => $analytics->getEventSummary($rangeMeta['rangeSpec'], $filters),
        'top_events' => $analytics->getTopEvents($rangeMeta['rangeSpec'], 10, $filters),
        'top_event_targets' => $analytics->getTopEventTargets($rangeMeta['rangeSpec'], 10, $filters),
    ];
    if($compareMeta['enabled']) {
        $report['compare'] = [
            'label' => $compareMeta['label'],
            'summary' => $analytics->getSummary($compareMeta['rangeSpec'], $filters),
            'quality' => $analytics->getSessionQuality($compareMeta['rangeSpec'], $filters),
            'summary404' => $analytics->get404Summary($compareMeta['rangeSpec']),
        ];
    }
    return $report;
}

protected function buildReportLines(array $report) {
    $lines = [];
    $lines[] = 'NativeAnalytics report';
    $lines[] = 'Generated: ' . $report['generated_at'];
    $lines[] = 'Range: ' . $report['range_label'];
    $lines[] = 'Filters: ' . $report['filters_label'];
    $lines[] = '';
    $lines[] = 'Summary';
    $lines[] = 'Views: ' . number_format((int) ($report['summary']['views'] ?? 0));
    $lines[] = 'Unique visitors: ' . number_format((int) ($report['summary']['uniques'] ?? 0));
    $lines[] = 'Sessions: ' . number_format((int) ($report['summary']['sessions'] ?? 0));
    $lines[] = 'Avg pages / session: ' . number_format((float) ($report['quality']['avg_pages_per_session'] ?? 0), 2);
    $lines[] = 'Single-page rate: ' . number_format((float) ($report['quality']['single_page_rate'] ?? 0), 1) . '%';
    $lines[] = '404 hits: ' . number_format((int) ($report['summary404']['views'] ?? 0));
    $lines[] = 'Tracked actions: ' . number_format((int) ($report['event_summary']['events'] ?? 0));
    $lines[] = 'Action sessions: ' . number_format((int) ($report['event_summary']['sessions'] ?? 0));
    $lines[] = '';

    if(!empty($report['compare'])) {
        $lines[] = 'Comparison';
        $lines[] = 'Compared against: ' . (string) ($report['compare']['label'] ?? '');
        $lines[] = 'Views: ' . number_format((int) ($report['compare']['summary']['views'] ?? 0)) . ' (' . $this->formatDeltaText((float) ($report['summary']['views'] ?? 0), (float) ($report['compare']['summary']['views'] ?? 0)) . ')';
        $lines[] = 'Unique visitors: ' . number_format((int) ($report['compare']['summary']['uniques'] ?? 0)) . ' (' . $this->formatDeltaText((float) ($report['summary']['uniques'] ?? 0), (float) ($report['compare']['summary']['uniques'] ?? 0)) . ')';
        $lines[] = 'Sessions: ' . number_format((int) ($report['compare']['summary']['sessions'] ?? 0)) . ' (' . $this->formatDeltaText((float) ($report['summary']['sessions'] ?? 0), (float) ($report['compare']['summary']['sessions'] ?? 0)) . ')';
        $lines[] = 'Avg pages / session: ' . number_format((float) ($report['compare']['quality']['avg_pages_per_session'] ?? 0), 2) . ' (' . $this->formatDeltaText((float) ($report['quality']['avg_pages_per_session'] ?? 0), (float) ($report['compare']['quality']['avg_pages_per_session'] ?? 0), 1) . ')';
        $lines[] = 'Single-page rate: ' . number_format((float) ($report['compare']['quality']['single_page_rate'] ?? 0), 1) . '% (' . $this->formatDeltaText((float) ($report['quality']['single_page_rate'] ?? 0), (float) ($report['compare']['quality']['single_page_rate'] ?? 0), 1) . ')';
        $lines[] = '404 hits: ' . number_format((int) ($report['compare']['summary404']['views'] ?? 0)) . ' (' . $this->formatDeltaText((float) ($report['summary404']['views'] ?? 0), (float) ($report['compare']['summary404']['views'] ?? 0)) . ')';
        $lines[] = '';
    }

    $sections = [
        'Top pages' => [$report['top_pages'], 'page'],
        'Top landing pages' => [$report['landing'], 'session_page'],
        'Top exit pages' => [$report['exits'], 'session_page'],
        'Top referrers' => [$report['referrers'], 'referrer'],
        'Internal search terms' => [$report['search_terms'], 'search'],
        'Campaigns / UTM traffic' => [$report['campaigns'], 'campaign'],
        '404 pages' => [$report['paths404'], '404'],
        'Top tracked actions' => [$report['top_events'], 'event'],
        'Top action targets' => [$report['top_event_targets'], 'event_target'],
    ];

    foreach($sections as $title => $bundle) {
        [$rows, $type] = $bundle;
        $lines[] = $title;
        if(!$rows) {
            $lines[] = 'No data.';
            $lines[] = '';
            continue;
        }
        $i = 1;
        foreach($rows as $row) {
            $lines[] = $this->formatReportRow($row, $type, $i);
            $i++;
        }
        $lines[] = '';
    }

    return $lines;
}

    protected function formatReportRow(array $row, $type, $index) {
        if($type === 'page') {
            $label = trim(strip_tags((string) (($row['page_title'] ?? '') ?: ($row['path'] ?? ''))));
            return $index . '. ' . $label . ' | ' . (string) ($row['path'] ?? '/') . ' | views ' . (int) ($row['views'] ?? 0) . ', uniques ' . (int) ($row['uniques'] ?? 0) . ', sessions ' . (int) ($row['sessions'] ?? 0);
        }
        if($type === 'session_page') {
            $label = trim(strip_tags((string) (($row['page_title'] ?? '') ?: ($row['path'] ?? ''))));
            return $index . '. ' . $label . ' | ' . (string) ($row['path'] ?? '/') . ' | sessions ' . (int) ($row['sessions'] ?? 0);
        }
        if($type === 'referrer') {
            return $index . '. ' . (string) ($row['referrer_host'] ?? '—') . ' | views ' . (int) ($row['views'] ?? 0);
        }
        if($type === 'search') {
            return $index . '. ' . (string) ($row['search_term'] ?? '—') . ' | views ' . (int) ($row['views'] ?? 0);
        }
        if($type === 'campaign') {
            return $index . '. ' . (string) ($row['label'] ?? '—') . ' | views ' . (int) ($row['views'] ?? 0) . ', uniques ' . (int) ($row['uniques'] ?? 0) . ', sessions ' . (int) ($row['sessions'] ?? 0);
        }
        if($type === '404') {
            return $index . '. ' . (string) ($row['path'] ?? '—') . ' | hits ' . (int) ($row['views'] ?? 0) . ', uniques ' . (int) ($row['uniques'] ?? 0);
        }
        if($type === 'event') {
            return $index . '. ' . (string) ($row['event_group'] ?? 'custom') . ' / ' . (string) ($row['event_name'] ?? 'event') . ' | ' . (string) ($row['event_label'] ?? '') . ' | events ' . (int) ($row['events'] ?? 0) . ', sessions ' . (int) ($row['sessions'] ?? 0);
        }
        if($type === 'event_target') {
            return $index . '. ' . (string) ($row['event_target'] ?? '—') . ' | ' . (string) ($row['event_group'] ?? '') . ' | events ' . (int) ($row['events'] ?? 0) . ', sessions ' . (int) ($row['sessions'] ?? 0);
        }
        return $index . '. ' . json_encode($row);
    }

    protected function buildSimplePdf(array $lines) {
        $pages = array_chunk($lines, 44);
        $objects = [];
        $objects[1] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[2] = 'PAGES_PLACEHOLDER';
        $pageNum = 3;
        $kids = [];

        foreach($pages as $pageLines) {
            $pageObj = $pageNum;
            $contentObj = $pageNum + 1;
            $kids[] = $pageObj . ' 0 R';
            $stream = "BT
/F1 10 Tf
14 TL
50 790 Td
";
            foreach($pageLines as $line) {
                $stream .= '(' . $this->escapePdfText($this->toPdfEncoding($line)) . ") Tj
T*
";
            }
            $stream .= "ET";
            $objects[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 1 0 R >> >> /Contents ' . $contentObj . ' 0 R >>';
            $objects[$contentObj] = '<< /Length ' . strlen($stream) . " >>
stream
" . $stream . "
endstream";
            $pageNum += 2;
        }

        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $catalogNum = $pageNum;
        $objects[$catalogNum] = '<< /Type /Catalog /Pages 2 0 R >>';

        ksort($objects);
        $pdf = "%PDF-1.4
";
        $offsets = [0];
        foreach($objects as $num => $obj) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj
" . $obj . "
endobj
";
        }
        $xrefPos = strlen($pdf);
        $pdf .= 'xref' . "
0 " . (count($objects) + 1) . "
";
        $pdf .= sprintf("%010d %05d f 
", 0, 65535);
        for($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d %05d n 
", $offsets[$i], 0);
        }
        $pdf .= 'trailer << /Size ' . (count($objects) + 1) . ' /Root ' . $catalogNum . " 0 R >>
startxref
" . $xrefPos . "
%%EOF";
        return $pdf;
    }

    protected function buildSimpleDocx(array $lines) {
        $tmp = tempnam(sys_get_temp_dir(), 'pwna_docx_');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $docBody = '';
        foreach($lines as $line) {
            if($line === '') {
                $docBody .= '<w:p/>';
                continue;
            }
            $docBody .= '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($line, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</w:t></w:r></w:p>';
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $docBody . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr></w:body></w:document>');
        $zip->close();
        $binary = file_get_contents($tmp);
        @unlink($tmp);
        return $binary === false ? '' : $binary;
    }

    protected function escapePdfText($text) {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    protected function toPdfEncoding($text) {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', (string) $text);
        return $converted !== false ? $converted : (string) $text;
    }

    protected function handlePostActions(NativeAnalytics $analytics) {
        $this->session->CSRF->validate();
        $action = $this->input->post('pwna_action');
        if($action === 'rebuild_today') {
            $analytics->rebuildDailyAggregate(date('Y-m-d'));
            $this->message("Rebuilt today's aggregate data.");
        }
        if($action === 'rebuild_yesterday') {
            $analytics->rebuildDailyAggregate(date('Y-m-d', strtotime('-1 day')));
            $this->message("Rebuilt yesterday's aggregate data.");
        }
        if($action === 'purge_hits') {
            $analytics->purgeOldHits();
            $this->message('Old raw hits purged.');
        }
        if($action === 'purge_realtime') {
            $analytics->purgeOldRealtimeSessions();
            $this->message('Old realtime sessions purged.');
        }
        if($action === 'reset_analytics_data') {
            $analytics->resetAnalyticsData();
            $this->warning('All analytics data has been reset.');
        }
    }

    protected function formatDate(NativeAnalytics $analytics, $value) {
        return $analytics->formatDisplayDate($value);
    }

    protected function formatDateTime(NativeAnalytics $analytics, $value) {
        return $analytics->formatDisplayDateTime($value);
    }

    protected function getRangeMeta(NativeAnalytics $analytics) {
        $selectedRange = (string) $this->input->get('range');
        if($selectedRange === '') $selectedRange = (string) $analytics->dashboardDefaultRange;
        $rangeDays = ['7d' => 7, '30d' => 30, '90d' => 90, '365d' => 365][$selectedRange] ?? 30;
        $fromDate = trim((string) $this->input->get('from_date'));
        $toDate = trim((string) $this->input->get('to_date'));
        $custom = ($fromDate !== '' || $toDate !== '');
        $rangeSpec = $custom ? $analytics->getDateRangeBetween($fromDate, $toDate, $rangeDays) : $analytics->getDateRangeForDays($rangeDays);
        $label = $custom ? $analytics->formatDisplayRange($rangeSpec) : ('Last ' . $rangeDays . ' days');
        return [
            'selectedRange' => $selectedRange,
            'rangeDays' => $rangeDays,
            'fromDate' => $custom ? $rangeSpec['start_date'] : '',
            'toDate' => $custom ? $rangeSpec['end_date'] : '',
            'rangeSpec' => $rangeSpec,
            'label' => $label,
        ];
    }

protected function renderBrandHeader(NativeAnalytics $analytics) {
    $version = 'v' . NativeAnalytics::VERSION;
    $donateUrl = 'https://www.paypal.com/donate/?business=BBZT3L4ES7U7A&no_recurring=0&item_name=Free+open-source+modules%2C+built+in+my+spare+time.+If+they+help+your+projects%2C+a+small+donation+keeps+development+going.+Thanx%21&currency_code=EUR';
    $settingsUrl = $this->wire('config')->urls->admin . 'module/edit?name=NativeAnalytics';
    $canManageModules = $this->user->isSuperuser() || $this->user->hasPermission('module-admin');
    $out = '<section class="pwna-brand pwna-panel">';
    $out .= '<div class="pwna-brand-title">';
    $out .= '<i class="fa fa-bar-chart pwna-brand-icon" aria-hidden="true"></i>';
    $out .= '<span>NativeAnalytics</span>';
    $out .= '<span class="pwna-brand-version">' . $this->sanitizer->entities($version) . '</span>';
    $out .= '<a class="pwna-donate-btn" href="' . $this->sanitizer->entities($donateUrl) . '" target="_blank" rel="noopener noreferrer">&#9829; Donate</a>';
    $out .= '</div>';
    if($canManageModules) {
        $out .= '<div class="pwna-brand-actions">';
        $out .= '<a class="pwna-settings-btn" href="' . $this->sanitizer->entities($settingsUrl) . '" title="Open NativeAnalytics module settings"><i class="fa fa-cog" aria-hidden="true"></i><span>Module settings</span></a>';
        $out .= '</div>';
    }
    $out .= '</section>';
    return $out;
}

protected function renderInlineCssFallback() {
    $file = __DIR__ . '/assets/admin.css';
    if(!is_file($file) || !is_readable($file)) return '';
    $css = (string) file_get_contents($file);
    if($css === '') return '';
    $css = preg_replace('!\/\*.*?\*\/!s', '', $css);
    $css = preg_replace('/\s+/', ' ', $css);
    return '<style>' . trim($css) . '</style>';
}


    protected function renderHealthPanel(array $health) {
        $rows = [
            ['Module folder', $this->sanitizer->entities((string) ($health['module_dir'] ?? ''))],
            ['Raw hits rows', '<span id="pwna-health-hits">' . number_format((int) ($health['hits_count'] ?? 0)) . '</span>'],
            ['Realtime sessions rows', '<span id="pwna-health-sessions">' . number_format((int) ($health['sessions_count'] ?? 0)) . '</span>'],
            ['Daily aggregate rows', '<span id="pwna-health-daily">' . number_format((int) ($health['daily_count'] ?? 0)) . '</span>'],
            ['Tracked events rows', '<span id="pwna-health-events">' . number_format((int) ($health['events_count'] ?? 0)) . '</span>'],
            ['Last raw hit', '<span id="pwna-health-last-hit">' . $this->sanitizer->entities((string) ($health['last_hit_at'] ?? '—')) . '</span>'],
            ['Last realtime session', '<span id="pwna-health-last-session">' . $this->sanitizer->entities((string) ($health['last_session_at'] ?? '—')) . '</span>'],
            ['Last tracked event', '<span id="pwna-health-last-event">' . $this->sanitizer->entities((string) ($health['last_event_at'] ?? '—')) . '</span>'],
        ];
        return $this->renderSimpleTable('Diagnostics', ['Check', 'Value'], $rows);
    }

protected function renderToolbar(array $rangeMeta, $pageId, $template, array $templates, $activeTab = 'overview') {
    $params = [
        'range' => $rangeMeta['selectedRange'],
        'from_date' => $rangeMeta['fromDate'],
        'to_date' => $rangeMeta['toDate'],
        'tab' => $activeTab,
    ];
    $engagementView = $activeTab === 'engagement' ? $this->getEngagementView() : 'metrics';
    if($activeTab === 'engagement' && $engagementView !== 'metrics') $params['engage_view'] = $engagementView;
    if($pageId > 0) $params['page_id'] = (int) $pageId;
    if($template !== '') $params['template'] = $template;
    $csvUrl = './export/?' . http_build_query($params);
    $pdfUrl = './pdf/?' . http_build_query($params);
    $docxUrl = './docx/?' . http_build_query($params);

    $out = '<form class="pwna-toolbar pwna-panel pwna-toolbar-panel" method="get">';
    $out .= $this->renderHelpIcon('The From and To fields override the quick range dropdown for the main analytics view.', 'Overview filters help', 'pwna-help-tip-corner pwna-help-tip-panel-corner');
    $out .= '<input type="hidden" name="tab" value="' . $this->sanitizer->entities($activeTab) . '">';
    if($activeTab === 'engagement') {
        $out .= '<input type="hidden" name="engage_view" value="' . $this->sanitizer->entities($engagementView) . '" data-pwna-engage-input="1">';
    }
    $out .= '<div class="pwna-toolbar-main">';
    $out .= '<div class="pwna-toolbar-left">';
    $out .= '<label>Quick range <select name="range">';
    foreach($this->getRangeOptions() as $value => $label) {
        $out .= '<option value="' . $this->sanitizer->entities($value) . '"' . ($rangeMeta['selectedRange'] === $value ? ' selected' : '') . '>' . $this->sanitizer->entities($label) . '</option>';
    }
    $out .= '</select></label>';
    $out .= '<label>From <input type="date" name="from_date" value="' . $this->sanitizer->entities($rangeMeta['fromDate']) . '"></label>';
    $out .= '<label>To <input type="date" name="to_date" value="' . $this->sanitizer->entities($rangeMeta['toDate']) . '"></label>';
    $out .= '<label>Page ID <input type="number" min="1" name="page_id" value="' . ($pageId > 0 ? (int) $pageId : '') . '"></label>';
    $out .= '<label>Template <select name="template"><option value="">All templates</option>';
    foreach($templates as $row) {
        $value = $row['label'];
        $out .= '<option value="' . $this->sanitizer->entities($value) . '"' . ($template === $value ? ' selected' : '') . '>' . $this->sanitizer->entities($value) . ' (' . (int) $row['views'] . ')</option>';
    }
    $out .= '</select></label>';
    $out .= '<button class="ui-button" type="submit">Apply</button></div>';
    $out .= '<div class="pwna-toolbar-right"><div class="pwna-export-actions">';
    $out .= '<button class="pwna-secondary-btn pwna-refresh-btn" type="submit">Refresh now</button>';
    $out .= '<a class="pwna-secondary-btn pwna-export-btn" href="' . $this->sanitizer->entities($csvUrl) . '">Export CSV</a>';
    $out .= '<a class="pwna-secondary-btn pwna-export-btn" href="' . $this->sanitizer->entities($pdfUrl) . '">Export PDF</a>';
    $out .= '<a class="pwna-secondary-btn pwna-export-btn" href="' . $this->sanitizer->entities($docxUrl) . '">Export DOCX</a>';
    $out .= '</div></div>';
    $out .= '</div>';
    $out .= '<div class="pwna-toolbar-meta">';
    $out .= '<div class="pwna-toolbar-status"><span class="pwna-note">Range: ' . $this->sanitizer->entities($rangeMeta['label']) . '</span></div>';
    $out .= '</div>';
    $out .= '</form>';
    return $out;
}

protected function renderCompareToolbar(array $rangeMeta, array $compareMeta, $pageId, $template, array $templates) {
    $params = [
        'range' => $rangeMeta['selectedRange'],
        'from_date' => $rangeMeta['fromDate'],
        'to_date' => $rangeMeta['toDate'],
        'tab' => 'compare',
        'compare' => $compareMeta['selected'],
    ];
    if($pageId > 0) $params['page_id'] = (int) $pageId;
    if($template !== '') $params['template'] = $template;
    $csvUrl = './export/?' . http_build_query($params);
    $pdfUrl = './pdf/?' . http_build_query($params);
    $docxUrl = './docx/?' . http_build_query($params);

    $out = '<form class="pwna-toolbar pwna-panel pwna-toolbar-panel" method="get">';
    $out .= $this->renderHelpIcon('Quick compare buttons apply ready-made period pairs. Use From and To only when you want a custom main period, then choose how it should be compared.', 'Compare help', 'pwna-help-tip-corner pwna-help-tip-panel-corner');
    $out .= '<input type="hidden" name="tab" value="compare">';
    $out .= '<input type="hidden" name="range" value="' . $this->sanitizer->entities($rangeMeta['selectedRange']) . '">';
    $out .= '<div class="pwna-toolbar-main">';
    $out .= '<div class="pwna-toolbar-left">';
    $out .= '<label>From <input type="date" name="from_date" value="' . $this->sanitizer->entities($rangeMeta['fromDate']) . '"></label>';
    $out .= '<label>To <input type="date" name="to_date" value="' . $this->sanitizer->entities($rangeMeta['toDate']) . '"></label>';
    $out .= '<label>Compare against <select name="compare">';
    foreach(['previous_period' => 'Previous period', 'previous_year' => 'Same period last year', 'none' => 'No comparison'] as $value => $label) {
        $out .= '<option value="' . $this->sanitizer->entities($value) . '"' . ($compareMeta['selected'] === $value ? ' selected' : '') . '>' . $this->sanitizer->entities($label) . '</option>';
    }
    $out .= '</select></label>';
    $out .= '<label>Page ID <input type="number" min="1" name="page_id" value="' . ($pageId > 0 ? (int) $pageId : '') . '"></label>';
    $out .= '<label>Template <select name="template"><option value="">All templates</option>';
    foreach($templates as $row) {
        $value = $row['label'];
        $out .= '<option value="' . $this->sanitizer->entities($value) . '"' . ($template === $value ? ' selected' : '') . '>' . $this->sanitizer->entities($value) . ' (' . (int) $row['views'] . ')</option>';
    }
    $out .= '</select></label>';
    $out .= '<button class="ui-button" type="submit">Apply comparison</button></div>';
    $out .= '<div class="pwna-toolbar-right"><div class="pwna-export-actions">';
    $out .= '<button class="pwna-secondary-btn pwna-refresh-btn" type="submit">Refresh now</button>';
    $out .= '<a class="pwna-secondary-btn pwna-export-btn" href="' . $this->sanitizer->entities($csvUrl) . '">Export CSV</a>';
    $out .= '<a class="pwna-secondary-btn pwna-export-btn" href="' . $this->sanitizer->entities($pdfUrl) . '">Export PDF</a>';
    $out .= '<a class="pwna-secondary-btn pwna-export-btn" href="' . $this->sanitizer->entities($docxUrl) . '">Export DOCX</a>';
    $out .= '</div></div>';
    $out .= '</div>';
    $out .= '<div class="pwna-toolbar-meta">';
    $out .= '<div class="pwna-toolbar-status">';
    $out .= '<span class="pwna-note">Selected period: ' . $this->sanitizer->entities($rangeMeta['label']) . '</span>';
    if($compareMeta['enabled']) {
        $out .= '<span class="pwna-note">Compared with: ' . $this->sanitizer->entities($compareMeta['label']) . '</span>';
    }
    $out .= '</div>';
    $out .= '<div class="pwna-export-actions">';
    $out .= '</div>';
    $out .= '<div class="pwna-quick-links">';
    $out .= '<span class="pwna-quick-links-label">Quick compare</span>';
    foreach($this->getCompareQuickPresets($pageId, $template) as $preset) {
        $out .= '<a class="ui-button pwna-quick-link" href="./?' . $this->sanitizer->entities(http_build_query($preset['params'])) . '">' . $this->sanitizer->entities($preset['label']) . '</a>';
    }
    $out .= '</div>';
    $out .= '</div>';
    $out .= '</form>';
    return $out;
}

protected function getRangeOptions($includeShort = true) {
    $options = [
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        '365d' => 'Last 365 days',
    ];
    if($includeShort) {
        $options = ['7d' => 'Last 7 days'] + $options;
    }
    return $options;
}

protected function getCompareQuickPresets($pageId = 0, $template = '') {
    $today = date('Y-m-d');
    $presets = [];

    $items = [
        [
            'label' => 'Last 7 vs previous 7',
            'params' => [
                'tab' => 'compare',
                'range' => '30d',
                'from_date' => date('Y-m-d', strtotime('-6 days')),
                'to_date' => $today,
                'compare' => 'previous_period',
            ],
        ],
        [
            'label' => 'Last 30 vs previous 30',
            'params' => [
                'tab' => 'compare',
                'range' => '30d',
                'from_date' => date('Y-m-d', strtotime('-29 days')),
                'to_date' => $today,
                'compare' => 'previous_period',
            ],
        ],
        [
            'label' => 'Month to date vs previous period',
            'params' => [
                'tab' => 'compare',
                'range' => '30d',
                'from_date' => date('Y-m-01'),
                'to_date' => $today,
                'compare' => 'previous_period',
            ],
        ],
        [
            'label' => 'Year to date vs last year',
            'params' => [
                'tab' => 'compare',
                'range' => '365d',
                'from_date' => date('Y-01-01'),
                'to_date' => $today,
                'compare' => 'previous_year',
            ],
        ],
    ];

    foreach($items as $item) {
        if($pageId > 0) $item['params']['page_id'] = (int) $pageId;
        if($template !== '') $item['params']['template'] = (string) $template;
        $presets[] = $item;
    }

    return $presets;
}

protected function getEngagementView() {
    $view = $this->sanitizer->name((string) $this->input->get('engage_view'));
    if(!in_array($view, ['metrics', 'helper'], true)) $view = 'metrics';
    return $view;
}

protected function renderWireTab($id, $title, $content, $isActive = false, $extraClass = '') {
    $classes = trim('WireTab pwna-wiretab ' . (string) $extraClass . ($isActive ? ' pwna-wiretab-initial' : ''));
    return '<div class="' . $this->sanitizer->entities($classes) . '" id="' . $this->sanitizer->entities($id) . '" title="' . $this->sanitizer->entities($title) . '">' . $content . '</div>';
}

protected function renderWireTabsScript($activeTab, $engagementView) {
    $labels = [
        'overview' => 'Overview',
        'engagement' => 'Engagement',
        'compare' => 'Compare',
        'sources' => 'Sources',
        'tech' => 'System',
    ];
    $payload = [
        'initialSlug' => in_array($activeTab, array_keys($labels), true) ? $activeTab : 'overview',
        'hasExplicitMainTab' => $this->input->get('tab') ? true : false,
        'storageKey' => 'pwna:main-tab',
        'labels' => $labels,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $script = <<<'HTML'
<script>
(function($){
  if(!$) return;
  function getStore(){
    try { return window.sessionStorage; } catch(e) { return null; }
  }
  function readStored(key) {
    var store = getStore();
    if(!store || !key) return '';
    try { return String(store.getItem(key) || ''); } catch(e) { return ''; }
  }
  function writeStored(key, value) {
    var store = getStore();
    if(!store || !key) return;
    try { store.setItem(key, String(value || '')); } catch(e) {}
  }
  function readUrlParam(name) {
    try { return new URL(window.location.href).searchParams.get(name) || ''; } catch(e) { return ''; }
  }
  function updateUrlParam(name, value) {
    if(!window.history || !window.history.replaceState) return;
    try {
      var url = new URL(window.location.href);
      if(value) url.searchParams.set(name, value);
      else url.searchParams.delete(name);
      window.history.replaceState({}, document.title, url.toString());
    } catch(e) {}
  }
  function slugFromLabel(cfg, label) {
    var labels = (cfg && cfg.labels) || {};
    for(var key in labels) {
      if(Object.prototype.hasOwnProperty.call(labels, key) && $.trim(labels[key]) === $.trim(label)) return key;
    }
    return '';
  }
  function getNavAnchors(rootSelector, cfg) {
    var $root = $(rootSelector);
    if(!$root.length) return $();
    return $root.find('ul.WireTabs a, ul.ui-tabs-nav a, .WireTabsNav a').filter(function(){
      return !!slugFromLabel(cfg, $(this).text());
    });
  }
  function activateBySlug(rootSelector, cfg, slug) {
    if(!slug || !cfg.labels || !cfg.labels[slug]) return;
    var label = cfg.labels[slug];
    var tryActivate = function() {
      var $links = getNavAnchors(rootSelector, cfg);
      if(!$links.length) return false;
      var $link = $links.filter(function(){
        return $.trim($(this).text()) === $.trim(label);
      }).first();
      if(!$link.length) return false;
      $link.trigger('click');
      return true;
    };
    if(!tryActivate()) {
      setTimeout(tryActivate, 50);
      setTimeout(tryActivate, 150);
      setTimeout(tryActivate, 300);
    }
  }
  function getActiveSlug(rootSelector, cfg) {
    var $root = $(rootSelector);
    if(!$root.length) return '';
    var $active = $root.find('ul.WireTabs li.ui-tabs-active a, ul.ui-tabs-nav li.ui-tabs-active a, .WireTabsNav li.ui-tabs-active a, ul.WireTabs li.ui-state-active a, ul.ui-tabs-nav li.ui-state-active a').first();
    if(!$active.length) {
      $active = getNavAnchors(rootSelector, cfg).filter(function(){
        var $a = $(this);
        return $a.attr('aria-selected') === 'true' || $a.parent().hasClass('ui-tabs-active') || $a.parent().hasClass('ui-state-active');
      }).first();
    }
    if(!$active.length) return '';
    return slugFromLabel(cfg, $active.text());
  }
  function syncMainTabState(rootSelector, cfg, fallbackSlug) {
    var slug = getActiveSlug(rootSelector, cfg) || fallbackSlug || 'overview';
    var label = (cfg.labels && cfg.labels[slug]) ? cfg.labels[slug] : 'Overview';
    writeStored(cfg.storageKey, label);
    updateUrlParam('tab', slug);
    if(slug !== 'engagement') updateUrlParam('engage_view', '');
    return slug;
  }
  $(document).ready(function(){
    var cfg = __PWNA_JSON__ || {};
    var explicit = readUrlParam('tab');
    var preferredSlug = (explicit && cfg.labels && cfg.labels[explicit]) ? explicit : '';
    if(!preferredSlug && !cfg.hasExplicitMainTab) {
      var storedLabel = readStored(cfg.storageKey);
      var storedSlug = slugFromLabel(cfg, storedLabel);
      if(storedSlug) preferredSlug = storedSlug;
    }
    if(!preferredSlug) preferredSlug = cfg.initialSlug || 'overview';

    activateBySlug('#pwna-wiretabs', cfg, preferredSlug);

    function bindNav() {
      var $links = getNavAnchors('#pwna-wiretabs', cfg);
      if(!$links.length) return false;
      $links.each(function(){
        var $link = $(this);
        if($link.data('pwnaTabBound')) return;
        $link.data('pwnaTabBound', 1);
        $link.on('click', function(){
          var clickedSlug = slugFromLabel(cfg, $link.text()) || preferredSlug || 'overview';
          setTimeout(function(){
            syncMainTabState('#pwna-wiretabs', cfg, clickedSlug);
          }, 0);
          setTimeout(function(){
            syncMainTabState('#pwna-wiretabs', cfg, clickedSlug);
          }, 80);
        });
      });
      return true;
    }

    if(!bindNav()) {
      setTimeout(bindNav, 80);
      setTimeout(bindNav, 180);
      setTimeout(bindNav, 320);
    }

    setTimeout(function(){ syncMainTabState('#pwna-wiretabs', cfg, preferredSlug); }, 60);
    setTimeout(function(){ syncMainTabState('#pwna-wiretabs', cfg, preferredSlug); }, 220);

    $(window).on('pagehide beforeunload', function(){
      syncMainTabState('#pwna-wiretabs', cfg, preferredSlug);
    });
  });
})(window.jQuery);
</script>
HTML;
    return str_replace('__PWNA_JSON__', $json, $script);
}


protected function renderEngagementSubnav($activeView) {
    $items = [
        'metrics' => 'Engagement data',
        'helper' => 'Tracking helper',
    ];
    $out = '<div class="pwna-subtabs-wrap"><nav class="pwna-subtabs" aria-label="Engagement sections">';
    foreach($items as $key => $label) {
        $class = 'pwna-subtab' . ($activeView === $key ? ' is-active' : '');
        $pressed = $activeView === $key ? 'true' : 'false';
        $out .= '<button type="button" class="' . $class . '" data-pwna-engage-switch="' . $this->sanitizer->entities($key) . '" aria-pressed="' . $pressed . '">' . $this->sanitizer->entities($label) . '</button>';
    }
    $out .= '</nav></div>';
    return $out;
}

protected function renderEngagementPanels($metricsContent, $helperContent, $activeView = 'metrics') {
    $metricsHidden = $activeView === 'helper' ? ' hidden' : '';
    $helperHidden = $activeView === 'helper' ? '' : ' hidden';
    $out = '<div class="pwna-engagement-panels">';
    $out .= '<div class="pwna-engagement-panel" data-pwna-engage-panel="metrics"' . $metricsHidden . '>' . $metricsContent . '</div>';
    $out .= '<div class="pwna-engagement-panel" data-pwna-engage-panel="helper"' . $helperHidden . '>' . $helperContent . '</div>';
    $out .= '</div>';
    return $out;
}

protected function getActiveTab() {
    $tab = $this->sanitizer->name((string) $this->input->get('tab'));
    if(!in_array($tab, ['overview', 'engagement', 'compare', 'sources', 'tech'], true)) $tab = 'overview';
    return $tab;
}

protected function getCompareMeta(NativeAnalytics $analytics, array $rangeMeta) {
    $selected = (string) $this->input->get('compare');
    if(!in_array($selected, ['none', 'previous_period', 'previous_year'], true)) $selected = 'previous_period';
    if($selected === 'none') {
        return ['selected' => 'none', 'enabled' => false, 'rangeSpec' => [], 'label' => 'No comparison'];
    }
    $current = $rangeMeta['rangeSpec'];
    $startTs = strtotime((string) $current['start_date']);
    $endTs = strtotime((string) $current['end_date']);
    $days = (int) floor(($endTs - $startTs) / 86400) + 1;
    if($selected === 'previous_year') {
        $compare = $analytics->getDateRangeBetween(date('Y-m-d', strtotime('-1 year', $startTs)), date('Y-m-d', strtotime('-1 year', $endTs)), $days);
        $label = $analytics->formatDisplayRange($compare) . ' (same period last year)';
        return ['selected' => $selected, 'enabled' => true, 'rangeSpec' => $compare, 'label' => $label];
    }
    $compareEndTs = strtotime('-1 day', $startTs);
    $compareStartTs = strtotime('-' . max(0, $days - 1) . ' days', $compareEndTs);
    $compare = $analytics->getDateRangeBetween(date('Y-m-d', $compareStartTs), date('Y-m-d', $compareEndTs), $days);
    $label = $analytics->formatDisplayRange($compare) . ' (previous period)';
    return ['selected' => $selected, 'enabled' => true, 'rangeSpec' => $compare, 'label' => $label];
}

protected function renderTabNav($activeTab, array $rangeMeta, $pageId, $template, $selectedCompare) {
    $base = [
        'range' => $rangeMeta['selectedRange'],
        'from_date' => $rangeMeta['fromDate'],
        'to_date' => $rangeMeta['toDate'],
        'compare' => $selectedCompare,
    ];
    if($pageId > 0) $base['page_id'] = (int) $pageId;
    if($template !== '') $base['template'] = (string) $template;
    $tabs = [
        'overview' => 'Overview',
        'engagement' => 'Engagement',
        'compare' => 'Compare',
        'sources' => 'Sources',
        'tech' => 'System',
    ];
    $out = '<nav class="pwna-tabs">';
    foreach($tabs as $key => $label) {
        $params = $base;
        $params['tab'] = $key;
        $class = 'pwna-tab' . ($activeTab === $key ? ' is-active' : '');
        $out .= '<a class="' . $class . '" href="./?' . $this->sanitizer->entities(http_build_query($params)) . '">' . $this->sanitizer->entities($label) . '</a>';
    }
    $out .= '</nav>';
    return $out;
}

protected function renderComparePanel(NativeAnalytics $analytics, array $rangeMeta, array $compareMeta, array $summary, array $compareSummary, array $quality, array $compareQuality, array $summary404, array $compareSummary404, array $series, array $compareSeries, $pageId = 0, $template = '', array $templates = []) {
    if(!$compareMeta['enabled']) {
        return '<div class="pwna-panel"><h2>Compare periods</h2><p class="pwna-note">Use the compare filters above to choose the main date range and the comparison mode for this tab.</p></div>';
    }

    $out = '<div class="pwna-panel"><h2>Compare periods</h2><p class="pwna-note">Selected period: ' . $this->sanitizer->entities($rangeMeta['label']) . '<br>Compared against: ' . $this->sanitizer->entities($compareMeta['label']) . '</p>';
    $out .= '<div class="pwna-legend"><span class="pwna-legend-item"><span class="pwna-swatch"></span>Selected period</span><span class="pwna-legend-item"><span class="pwna-swatch compare"></span>Comparison period</span></div>';
    $out .= $this->renderComparisonCards($summary, $compareSummary, $quality, $compareQuality, $summary404, $compareSummary404);
    $out .= '</div>';

    $out .= '<div class="pwna-grid-2">';
    $out .= '<div class="pwna-panel"><h2>Views comparison</h2><p class="pwna-note">The chart aligns both periods by slot so you can compare like-for-like days across the selected range.</p>' . $analytics->renderComparisonChart($series, $compareSeries, 'views', 'Views comparison chart', 'Selected period', 'Comparison period') . '</div>';
    $out .= '<div class="pwna-panel"><h2>Comparison summary</h2>' . $this->renderComparisonSummaryTable($summary, $compareSummary, $quality, $compareQuality, $summary404, $compareSummary404) . '</div>';
    $out .= '</div>';

    return $out;
}

protected function renderComparisonCards(array $summary, array $compareSummary, array $quality, array $compareQuality, array $summary404, array $compareSummary404) {
    $cards = [
        ['Views', (float) ($summary['views'] ?? 0), (float) ($compareSummary['views'] ?? 0), 0],
        ['Unique visitors', (float) ($summary['uniques'] ?? 0), (float) ($compareSummary['uniques'] ?? 0), 0],
        ['Sessions', (float) ($summary['sessions'] ?? 0), (float) ($compareSummary['sessions'] ?? 0), 0],
        ['Avg pages / session', (float) ($quality['avg_pages_per_session'] ?? 0), (float) ($compareQuality['avg_pages_per_session'] ?? 0), 2],
        ['Single-page rate', (float) ($quality['single_page_rate'] ?? 0), (float) ($compareQuality['single_page_rate'] ?? 0), 1],
        ['404 hits', (float) ($summary404['views'] ?? 0), (float) ($compareSummary404['views'] ?? 0), 0],
    ];
    $out = '<div class="pwna-compare-cards">';
    foreach($cards as $card) {
        [$label, $currentValue, $compareValue, $decimals] = $card;
        $currentText = number_format($currentValue, $decimals);
        $compareText = number_format($compareValue, $decimals);
        if($label === 'Single-page rate') {
            $currentText .= '%';
            $compareText .= '%';
        }
        $out .= '<div class="pwna-compare-card">';
        $out .= '<div class="pwna-card-label">' . $this->sanitizer->entities($label) . '</div>';
        $out .= '<div class="pwna-card-value">' . $currentText . '</div>';
        $out .= '<div class="pwna-card-sub">Comparison: ' . $compareText . '</div>';
        $out .= '<div class="pwna-compare-meta"><span>Change</span>' . $this->renderDeltaBadge($currentValue, $compareValue, $decimals) . '</div>';
        $out .= '</div>';
    }
    $out .= '</div>';
    return $out;
}

protected function renderComparisonSummaryTable(array $summary, array $compareSummary, array $quality, array $compareQuality, array $summary404, array $compareSummary404) {
    $rows = [
        ['Views', number_format((int) ($summary['views'] ?? 0)), number_format((int) ($compareSummary['views'] ?? 0)), $this->renderDeltaBadge((float) ($summary['views'] ?? 0), (float) ($compareSummary['views'] ?? 0))],
        ['Unique visitors', number_format((int) ($summary['uniques'] ?? 0)), number_format((int) ($compareSummary['uniques'] ?? 0)), $this->renderDeltaBadge((float) ($summary['uniques'] ?? 0), (float) ($compareSummary['uniques'] ?? 0))],
        ['Sessions', number_format((int) ($summary['sessions'] ?? 0)), number_format((int) ($compareSummary['sessions'] ?? 0)), $this->renderDeltaBadge((float) ($summary['sessions'] ?? 0), (float) ($compareSummary['sessions'] ?? 0))],
        ['Avg pages / session', number_format((float) ($quality['avg_pages_per_session'] ?? 0), 2), number_format((float) ($compareQuality['avg_pages_per_session'] ?? 0), 2), $this->renderDeltaBadge((float) ($quality['avg_pages_per_session'] ?? 0), (float) ($compareQuality['avg_pages_per_session'] ?? 0), 1)],
        ['Single-page rate', number_format((float) ($quality['single_page_rate'] ?? 0), 1) . '%', number_format((float) ($compareQuality['single_page_rate'] ?? 0), 1) . '%', $this->renderDeltaBadge((float) ($quality['single_page_rate'] ?? 0), (float) ($compareQuality['single_page_rate'] ?? 0), 1)],
        ['404 hits', number_format((int) ($summary404['views'] ?? 0)), number_format((int) ($compareSummary404['views'] ?? 0)), $this->renderDeltaBadge((float) ($summary404['views'] ?? 0), (float) ($compareSummary404['views'] ?? 0))],
    ];
    return $this->renderSimpleTableBodyOnly(['Metric', 'Selected period', 'Comparison', 'Change'], $rows);
}

protected function renderDeltaBadge($current, $compare, $decimals = 0) {
    $delta = $this->calculateDelta($current, $compare);
    $text = $this->formatDeltaText($current, $compare, $decimals);
    $class = 'pwna-delta is-flat';
    if($delta > 0.001) $class = 'pwna-delta is-up';
    elseif($delta < -0.001) $class = 'pwna-delta is-down';
    return '<span class="' . $class . '">' . $this->sanitizer->entities($text) . '</span>';
}

protected function calculateDelta($current, $compare) {
    $current = (float) $current;
    $compare = (float) $compare;
    if(abs($compare) < 0.00001) {
        if(abs($current) < 0.00001) return 0.0;
        return 100.0;
    }
    return (($current - $compare) / $compare) * 100;
}

protected function formatDeltaText($current, $compare, $decimals = 0) {
    $delta = $this->calculateDelta($current, $compare);
    if(abs((float) $compare) < 0.00001 && abs((float) $current) < 0.00001) return '0%';
    if(abs((float) $compare) < 0.00001) return 'new';
    $prefix = $delta > 0 ? '+' : '';
    return $prefix . number_format($delta, max(0, (int) $decimals)) . '%';
}

protected function renderHelpIcon($text, $label = 'Help', $extraClass = '') {
    $text = trim((string) $text);
    if($text === '') return '';
    $class = trim('pwna-help-tip ' . (string) $extraClass);
    return '<span class="' . $this->sanitizer->entities($class) . '" tabindex="0" aria-label="' . $this->sanitizer->entities($label) . '"><span class="pwna-help-tip-icon">?</span><span class="pwna-help-tip-bubble">' . nl2br($this->sanitizer->entities($text)) . '</span></span>';
}

    protected function renderManageActions() {
        if(!$this->user->hasPermission('nativeanalytics-manage')) return '';
        $csrfName = $this->session->CSRF->getTokenName();
        $csrfValue = $this->session->CSRF->getTokenValue();
        $actions = [
            'rebuild_today' => [
                'label' => 'Rebuild today',
                'help' => "Recalculates today's daily summary from raw tracked hits. Useful after testing or when you want to refresh today's totals immediately.",
            ],
            'rebuild_yesterday' => [
                'label' => 'Rebuild yesterday',
                'help' => "Recalculates yesterday's daily summary from raw tracked hits.",
            ],
            'purge_hits' => [
                'label' => 'Purge old raw hits',
                'help' => 'Deletes older raw page-hit records according to the module retention rules. Aggregated summary data stays available.',
            ],
            'purge_realtime' => [
                'label' => 'Purge old realtime sessions',
                'help' => 'Removes stale current-visitor rows that are older than the realtime visitor window.',
            ],
        ];
        $out = '<div class="pwna-panel"><h2>Maintenance</h2><div class="pwna-actions">';
        foreach($actions as $value => $item) {
            $out .= '<div class="pwna-action-item">';
            $out .= '<div class="pwna-action-button-wrap">';
            $out .= '<form method="post">';
            $out .= '<input type="hidden" name="' . $this->sanitizer->entities($csrfName) . '" value="' . $this->sanitizer->entities($csrfValue) . '">';
            $out .= '<input type="hidden" name="pwna_action" value="' . $this->sanitizer->entities($value) . '">';
            $out .= '<button class="ui-button pwna-button-tip" type="submit" data-pwna-tip="' . $this->sanitizer->entities($item['help']) . '">' . $this->sanitizer->entities($item['label']) . '</button></form>';
            $out .= '</div>';
            $out .= '</div>';
        }
        $out .= '</div>';
        $out .= '<div class="pwna-danger-zone">';
        $out .= '<h3>Danger zone</h3>';
        $out .= '<p class="pwna-note">Reset analytics data permanently deletes all tracked page views, sessions, daily aggregates and engagement events. Module settings and dashboard pages stay intact.</p>';
        $out .= "<form method=\"post\" onsubmit=\"return confirm('Are you sure you want to reset all analytics data? This cannot be undone.');\">";
        $out .= '<input type="hidden" name="' . $this->sanitizer->entities($csrfName) . '" value="' . $this->sanitizer->entities($csrfValue) . '">';
        $out .= '<input type="hidden" name="pwna_action" value="reset_analytics_data">';
        $out .= '<button class="ui-button pwna-danger-button" type="submit">Reset analytics data</button>';
        $out .= '</form>';
        $out .= '</div></div>';
        return $out;
    }

    protected function renderCards(array $summary, array $current, array $summary404, array $quality) {
        $cards = [
            ['Views', number_format((int) ($summary['views'] ?? 0)), 'views'],
            ['Unique visitors', number_format((int) ($summary['uniques'] ?? 0)), 'uniques'],
            ['Sessions', number_format((int) ($summary['sessions'] ?? 0)), 'sessions'],
            ['Avg pages / session', number_format((float) ($quality['avg_pages_per_session'] ?? 0), 2), 'avg_pages_per_session'],
            ['Single-page rate', number_format((float) ($quality['single_page_rate'] ?? 0), 1) . '%', 'single_page_rate'],
            ['Current visitors', number_format((int) ($current['current_visitors'] ?? 0)), 'current_visitors'],
            ['404 hits', number_format((int) ($summary404['views'] ?? 0)), 'hits_404'],
        ];
        $out = '<div class="pwna-cards">';
        foreach($cards as $card) {
            $out .= '<div class="pwna-card"><div class="pwna-card-label">' . $this->sanitizer->entities($card[0]) . '</div><div class="pwna-card-value" data-pwna-card="' . $this->sanitizer->entities($card[2]) . '">' . $card[1] . '</div></div>';
        }
        $out .= '</div>';
        return $out;
    }

    protected function renderCurrentVisitorsPanel(NativeAnalytics $analytics, array $rows, $minutes) {
        $out = '<div class="pwna-panel" id="pwna-current-panel"><h2>Current visitors</h2>';
        $out .= '<p class="pwna-note" id="pwna-current-note">Active in the last ' . (int) $minutes . ' minutes.</p>';
        if(!$rows) {
            $out .= '<div id="pwna-current-body"><p class="pwna-empty">No active visitors right now.</p></div></div>';
            return $out;
        }
        $mapped = [];
        foreach($rows as $row) {
            $pageLabel = $this->sanitizer->entities($row['current_path']);
            if(!empty($row['page_title'])) {
                $pageLabel = '<strong>' . $this->sanitizer->entities(strip_tags((string) $row['page_title'])) . '</strong><br><span class="pwna-muted">' . $this->sanitizer->entities((string) $row['current_path']) . '</span>'; 
            }
            if((int) ($row['status_code'] ?? 200) === 404) {
                $pageLabel .= '<br><span class="pwna-badge">404</span>';
            }
            $mapped[] = [
                $pageLabel,
                $this->sanitizer->entities((string) $row['device_type']),
                $this->sanitizer->entities((string) $row['browser']),
                $this->sanitizer->entities($this->formatDateTime($analytics, $row['last_seen_at'])),
            ];
        }
        $out .= '<div id="pwna-current-body">' . $this->renderSimpleTableBodyOnly(['Page', 'Device', 'Browser', 'Last seen'], $mapped) . '</div>';
        $out .= '</div>';
        return $out;
    }

    protected function renderSimpleTable($title, array $headers, array $rows) {
        $out = '<div class="pwna-panel"><h2>' . $this->sanitizer->entities($title) . '</h2>';
        if(!$rows) return $out . '<p class="pwna-empty">No data yet.</p></div>';
        return $out . $this->renderSimpleTableBodyOnly($headers, $rows) . '</div>';
    }

    protected function renderSimpleTableBodyOnly(array $headers, array $rows) {
        $out = '<div class="pwna-table-wrap"><table class="pwna-table"><thead><tr>';
        foreach($headers as $header) $out .= '<th>' . $this->sanitizer->entities($header) . '</th>';
        $out .= '</tr></thead><tbody>';
        foreach($rows as $row) {
            $out .= '<tr>';
            foreach($row as $cell) $out .= '<td>' . $cell . '</td>';
            $out .= '</tr>';
        }
        $out .= '</tbody></table></div>';
        return $out;
    }

protected function renderChartTooltipScript() {
    return '<script>(function(){function init(){document.querySelectorAll(".pwna-chart-wrap").forEach(function(wrap){var tip=wrap.querySelector(".pwna-chart-tooltip");if(!tip||wrap.dataset.pwnaTipInit==="1")return;wrap.dataset.pwnaTipInit="1";var dayEl=tip.querySelector(".pwna-chart-tooltip-day");var timeEl=tip.querySelector(".pwna-chart-tooltip-time");var viewsEl=tip.querySelector("[data-pwna-tip=views]");var uniquesEl=tip.querySelector("[data-pwna-tip=uniques]");var sessionsEl=tip.querySelector("[data-pwna-tip=sessions]");var compareWrap=tip.querySelector(".pwna-chart-tooltip-compare");var compareDay=tip.querySelector("[data-pwna-tip=compare-day]");var compareViews=tip.querySelector("[data-pwna-tip=compare-views]");var compareUniques=tip.querySelector("[data-pwna-tip=compare-uniques]");var compareSessions=tip.querySelector("[data-pwna-tip=compare-sessions]");function place(ev){var rect=wrap.getBoundingClientRect();var x=(ev.clientX-rect.left)+14;var y=(ev.clientY-rect.top)-12;var maxX=Math.max(8, rect.width-tip.offsetWidth-8);var maxY=Math.max(8, rect.height-tip.offsetHeight-8);tip.style.left=Math.max(8, Math.min(x, maxX))+"px";tip.style.top=Math.max(8, Math.min(y, maxY))+"px";}function activate(point,ev){wrap.querySelectorAll(".pwna-point.is-active").forEach(function(el){el.classList.remove("is-active");});point.classList.add("is-active");dayEl.textContent=point.getAttribute("data-label")||"";var timeText=point.getAttribute("data-time")||"";timeEl.textContent=timeText;timeEl.hidden=!timeText;viewsEl.textContent=point.getAttribute("data-views")||"0";uniquesEl.textContent=point.getAttribute("data-uniques")||"0";sessionsEl.textContent=point.getAttribute("data-sessions")||"0";var hasCompare=point.hasAttribute("data-compare-label");if(compareWrap){compareWrap.hidden=!hasCompare;if(hasCompare){compareDay.textContent=point.getAttribute("data-compare-label")||"";compareViews.textContent=point.getAttribute("data-compare-views")||"0";compareUniques.textContent=point.getAttribute("data-compare-uniques")||"0";compareSessions.textContent=point.getAttribute("data-compare-sessions")||"0";}}tip.hidden=false;place(ev);}wrap.querySelectorAll(".pwna-point").forEach(function(point){point.addEventListener("mouseenter", function(ev){activate(point,ev);});point.addEventListener("mousemove", function(ev){place(ev);});});wrap.addEventListener("mouseleave", function(){wrap.querySelectorAll(".pwna-point.is-active").forEach(function(el){el.classList.remove("is-active");});tip.hidden=true;});});}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded", init);}else{init();}})();</script>';
}

        protected function renderAutoRefreshScript(array $rangeMeta, $minutes, $pageId, $template) {
        /** @var NativeAnalytics $analytics */
        $analytics = $this->modules->get('NativeAnalytics');
        $endpoint = $analytics->getRealtimeEndpointUrl();
        $payload = [
            'endpoint' => $endpoint,
            'rangeDays' => max(1, (int) $rangeMeta['rangeDays']),
            'fromDate' => (string) $rangeMeta['fromDate'],
            'toDate' => (string) $rangeMeta['toDate'],
            'minutes' => (int) $minutes,
            'pageId' => (int) $pageId,
            'template' => (string) $template,
        ];
        $json = json_encode($payload);
        return <<<HTML
<script>
(function(){
  var cfg = {$json};
  if(!cfg || !cfg.endpoint || !window.fetch) return;
  function fmt(n, d, suf) {
    var num = Number(n || 0);
    try {
      var out = new Intl.NumberFormat(undefined, { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).format(num);
      return out + (suf || '');
    } catch(e) {
      return String(num) + (suf || '');
    }
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function(c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c] || c;
    });
  }
  function setText(sel, val) {
    var el = document.querySelector(sel);
    if(el) el.textContent = val;
  }
  function updateCards(data) {
    if(!data) return;
    setText('[data-pwna-card=views]', fmt(data.summary && data.summary.views));
    setText('[data-pwna-card=uniques]', fmt(data.summary && data.summary.uniques));
    setText('[data-pwna-card=sessions]', fmt(data.summary && data.summary.sessions));
    setText('[data-pwna-card=avg_pages_per_session]', fmt(data.sessionQuality && data.sessionQuality.avg_pages_per_session, 2));
    setText('[data-pwna-card=single_page_rate]', fmt(data.sessionQuality && data.sessionQuality.single_page_rate, 1, '%'));
    setText('[data-pwna-card=current_visitors]', fmt(data.current && data.current.current_visitors));
    setText('[data-pwna-card=hits_404]', fmt(data.summary404 && data.summary404.views));
    setText('#pwna-health-hits', fmt(data.health && data.health.hits_count));
    setText('#pwna-health-sessions', fmt(data.health && data.health.sessions_count));
    setText('#pwna-health-daily', fmt(data.health && data.health.daily_count));
    setText('#pwna-health-events', fmt(data.health && data.health.events_count));
    setText('#pwna-health-last-hit', (data.health && data.health.last_hit_at) || '—');
    setText('#pwna-health-last-session', (data.health && data.health.last_session_at) || '—');
    setText('#pwna-health-last-event', (data.health && data.health.last_event_at) || '—');
  }
  function renderCurrent(rows) {
    var note = document.getElementById('pwna-current-note');
    if(note) note.textContent = 'Active in the last ' + cfg.minutes + ' minutes.';
    var wrap = document.getElementById('pwna-current-body');
    if(!wrap) return;
    if(!rows || !rows.length) {
      wrap.innerHTML = '<p class="pwna-empty">No active visitors right now.</p>';
      return;
    }
    var html = '<div class="pwna-table-wrap"><table class="pwna-table"><thead><tr><th>Page</th><th>Device</th><th>Browser</th><th>Last seen</th></tr></thead><tbody>';
    rows.forEach(function(row) {
      var page = esc(row.current_path || '/');
      var title = String(row.page_title || '').replace(/<[^>]*>/g, '').trim();
      if(title) {
        page = '<strong>' + esc(title) + '</strong><br><span class="pwna-muted">' + esc(row.current_path || '/') + '</span>';
      }
      if(Number(row.status_code || 200) === 404) page += '<br><span class="pwna-badge">404</span>';
      html += '<tr><td>' + page + '</td><td>' + esc(row.device_type || '') + '</td><td>' + esc(row.browser || '') + '</td><td>' + esc(row.last_seen_at || '') + '</td></tr>';
    });
    html += '</tbody></table></div>';
    wrap.innerHTML = html;
  }
  function refresh() {
    var url = new URL(cfg.endpoint, window.location.origin);
    url.searchParams.set('range_days', String(cfg.rangeDays));
    url.searchParams.set('minutes', String(cfg.minutes));
    if(cfg.fromDate) url.searchParams.set('from_date', cfg.fromDate);
    if(cfg.toDate) url.searchParams.set('to_date', cfg.toDate);
    if(cfg.pageId > 0) url.searchParams.set('page_id', String(cfg.pageId));
    if(cfg.template) url.searchParams.set('template', cfg.template);
    fetch(url.toString(), { credentials: 'same-origin' })
      .then(function(r) { return r.ok ? r.json() : null; })
      .then(function(data) {
        if(!data || data.ok === false) return;
        updateCards(data);
        renderCurrent(data.currentVisitors || []);
      })
      .catch(function(){});
  }
  refresh();
  setInterval(refresh, 10000);
})();
</script>
HTML;
    }

    protected function mapTopPages(array $rows) {
        $mapped = [];
        foreach($rows as $row) {
            $pageLabel = $this->sanitizer->entities($row['path']);
            if(!empty($row['page_title'])) {
                $pageLabel = '<strong>' . $this->sanitizer->entities(strip_tags((string) $row['page_title'])) . '</strong><br><span class="pwna-muted">' . $this->sanitizer->entities((string) $row['path']) . '</span>'; 
            }
            $mapped[] = [$pageLabel, number_format((int) $row['views']), number_format((int) $row['uniques']), number_format((int) $row['sessions'])];
        }
        return $mapped;
    }

    protected function mapSessionPageRows(array $rows) {
        $mapped = [];
        foreach($rows as $row) {
            $pageLabel = $this->sanitizer->entities($row['path']);
            if(!empty($row['page_title'])) {
                $pageLabel = '<strong>' . $this->sanitizer->entities(strip_tags((string) $row['page_title'])) . '</strong><br><span class="pwna-muted">' . $this->sanitizer->entities((string) $row['path']) . '</span>'; 
            }
            $mapped[] = [$pageLabel, number_format((int) ($row['sessions'] ?? 0))];
        }
        return $mapped;
    }

    protected function mapCampaignRows(array $rows) {
        $mapped = [];
        foreach($rows as $row) {
            $mapped[] = [
                '<span class="pwna-break">' . $this->sanitizer->entities((string) ($row['label'] ?? '—')) . '</span>',
                number_format((int) ($row['views'] ?? 0)),
                number_format((int) ($row['uniques'] ?? 0)),
                number_format((int) ($row['sessions'] ?? 0)),
            ];
        }
        return $mapped;
    }

    protected function map404Rows(array $rows) {
        $mapped = [];
        foreach($rows as $row) {
            $mapped[] = [
                '<span class="pwna-break">' . $this->sanitizer->entities($row['path']) . '</span>',
                number_format((int) $row['views']),
                number_format((int) $row['uniques']),
            ];
        }
        return $mapped;
    }

    protected function mapGenericRows(array $rows, $field = 'label') {
        $mapped = [];
        foreach($rows as $row) {
            $label = isset($row[$field]) ? $row[$field] : ($row['label'] ?? '—');
            $mapped[] = [$this->sanitizer->entities((string) $label), number_format((int) ($row['views'] ?? 0))];
        }
        return $mapped;
    }




protected function renderEventHelperPanel() {
    $linkSnippet = '<a href="/kontakt/" data-pwna-event="cta_contact" data-pwna-group="custom" data-pwna-label="Hero contact CTA">Contact</a>';
    $buttonSnippet = '<button type="button" data-pwna-event="cta_demo" data-pwna-group="custom" data-pwna-label="Homepage demo button">Demo</button>';
    $generatedDefault = '<a href="/kontakt/" data-pwna-event="cta_contact" data-pwna-group="custom" data-pwna-label="Hero contact CTA">Contact</a>';

    $html = '<div class="pwna-panel pwna-helper-panel">';
    $html .= '<h2>Tracking helper</h2>';
    $html .= '<p class="pwna-note">Use these examples and the generator below when you want custom CTA buttons or other important actions to appear in the <strong>Engagement</strong> tab.</p>';
    $html .= '<div class="pwna-helper-inner">';
    $html .= '<div class="pwna-helper-block">';
    $html .= '<h3>1. Add a custom CTA to a link</h3>';
    $html .= '<p class="pwna-note">Good for hero buttons, contact links, pricing CTAs, booking links, demo requests and similar actions.</p>';
    $html .= '<div class="pwna-code-row"><span class="pwna-copy-note">Copy-paste example</span><button type="button" class="pwna-secondary-btn pwna-copy-btn" data-pwna-copy-target="#pwna-code-link">Copy</button></div>';
    $html .= '<pre class="pwna-code" id="pwna-code-link"><code>' . $this->sanitizer->entities($linkSnippet) . '</code></pre>';
    $html .= '</div>';

    $html .= '<div class="pwna-helper-block">';
    $html .= '<h3>2. Add a custom CTA to a button</h3>';
    $html .= '<p class="pwna-note">Useful for JS buttons, modal openers, AJAX actions, tabs, configurators and other buttons that do not behave like normal links.</p>';
    $html .= '<div class="pwna-code-row"><span class="pwna-copy-note">Copy-paste example</span><button type="button" class="pwna-secondary-btn pwna-copy-btn" data-pwna-copy-target="#pwna-code-button">Copy</button></div>';
    $html .= '<pre class="pwna-code" id="pwna-code-button"><code>' . $this->sanitizer->entities($buttonSnippet) . '</code></pre>';
    $html .= '</div>';

    $html .= '<div class="pwna-helper-grid">';
    $html .= '<div class="pwna-helper-block pwna-generator" data-pwna-generator="1">';
    $html .= '<h3>Mini snippet generator</h3>';
    $html .= '<p class="pwna-note">Fill in the fields below and the module will build a ready-to-paste HTML snippet for your templates.</p>';
    $html .= '<div class="pwna-gen-grid">';
    $html .= '<label class="pwna-field"><span>Element type</span><select data-pwna-gen="element"><option value="a">Link</option><option value="button">Button</option></select></label>';
    $html .= '<label class="pwna-field"><span>Visible text</span><input type="text" value="Contact" data-pwna-gen="text"></label>';
    $html .= '<label class="pwna-field"><span>Target URL</span><input type="text" value="/kontakt/" data-pwna-gen="href"></label>';
    $html .= '<label class="pwna-field"><span>Button type</span><select data-pwna-gen="button-type"><option value="button">button</option><option value="submit">submit</option></select></label>';
    $html .= '<label class="pwna-field"><span>Event name</span><input type="text" value="cta_contact" data-pwna-gen="event"></label>';
    $html .= '<label class="pwna-field"><span>Group</span><input type="text" value="custom" data-pwna-gen="group"></label>';
    $html .= '<label class="pwna-field pwna-span-all"><span>Label shown in analytics</span><input type="text" value="Hero contact CTA" data-pwna-gen="label"></label>';
    $html .= '</div>';
    $html .= '<div class="pwna-helper-actions"><button type="button" class="pwna-secondary-btn" data-pwna-generate="1">Generate snippet</button><button type="button" class="pwna-secondary-btn pwna-copy-btn" data-pwna-copy-target="#pwna-generated-code">Copy generated</button></div>';
    $html .= '<div class="pwna-generated-wrap"><span class="pwna-copy-note">Generated HTML snippet</span><pre class="pwna-code" id="pwna-generated-code"><code>' . $this->sanitizer->entities($generatedDefault) . '</code></pre></div>';
    $html .= '</div>';

    $html .= '<div class="pwna-helper-block">';
    $html .= '<h3>What each attribute means</h3>';
    $html .= '<ul class="pwna-list">';
    $html .= '<li><code>data-pwna-event</code> = internal action name, for example <code>cta_contact</code>, <code>cta_demo</code>, <code>cta_book</code></li>';
    $html .= '<li><code>data-pwna-group</code> = action group, for example <code>custom</code>, <code>lead</code>, <code>sales</code>, <code>navigation</code></li>';
    $html .= '<li><code>data-pwna-label</code> = readable label shown in analytics, for example <code>Hero contact CTA</code></li>';
    $html .= '</ul>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<div class="pwna-helper-grid">';
    $html .= '<div class="pwna-helper-block">';
    $html .= '<h3>Where to add it</h3>';
    $html .= '<ul class="pwna-list">';
    $html .= '<li>Directly in your template markup, for example in <code>_main.php</code>, <code>home.php</code> or a section include.</li>';
    $html .= '<li>On links and buttons inside repeaters, blocks, hero sections or cards.</li>';
    $html .= '<li>On important conversion actions only, so the Engagement tab stays clean and useful.</li>';
    $html .= '</ul>';
    $html .= '</div>';

    $html .= '<div class="pwna-helper-block">';
    $html .= '<h3>Auto-tracked already</h3>';
    $html .= '<ul class="pwna-list">';
    $html .= '<li>Form submissions</li>';
    $html .= '<li>File downloads such as PDF, DOCX, XLSX, ZIP and CSV</li>';
    $html .= '<li><code>tel:</code> clicks</li>';
    $html .= '<li><code>mailto:</code> clicks</li>';
    $html .= '<li>Outbound link clicks</li>';
    $html .= '</ul>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<div class="pwna-helper-grid">';
    $html .= '<div class="pwna-helper-block">';
    $html .= '<h3>Tips for cleaner reports</h3>';
    $html .= '<ul class="pwna-list">';
    $html .= '<li>Use short and consistent event names such as <code>cta_contact</code>, <code>cta_offer</code>, <code>cta_buy</code>.</li>';
    $html .= '<li>Use labels for placement details, for example <code>Footer contact CTA</code> or <code>Sidebar demo CTA</code>.</li>';
    $html .= '<li>Avoid tracking every tiny click. Track actions that actually matter for leads, sales or engagement.</li>';
    $html .= '</ul>';
    $html .= '</div>';

    $html .= '<div class="pwna-helper-block">';
    $html .= '<h3>Recommended naming pattern</h3>';
    $html .= '<ul class="pwna-list">';
    $html .= '<li><code>cta_*</code> for important call-to-action clicks</li>';
    $html .= '<li><code>lead_*</code> for lead generation actions</li>';
    $html .= '<li><code>sales_*</code> for product and checkout actions</li>';
    $html .= '<li><code>nav_*</code> for strategic navigation clicks you want to monitor</li>';
    $html .= '</ul>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

protected function renderEventCards(array $all, array $forms, array $downloads, array $contacts, array $navigation) {
        $cards = [
            ['Tracked actions', (int) ($all['events'] ?? 0)],
            ['Form submits', (int) ($forms['events'] ?? 0)],
            ['Downloads', (int) ($downloads['events'] ?? 0)],
            ['Contact clicks', (int) ($contacts['events'] ?? 0)],
            ['Outbound clicks', (int) ($navigation['events'] ?? 0)],
        ];
        $out = '<div class="pwna-cards">';
        foreach($cards as $card) {
            $out .= '<div class="pwna-card"><div class="pwna-card-label">' . $this->sanitizer->entities($card[0]) . '</div><div class="pwna-card-value">' . number_format((int) $card[1]) . '</div></div>';
        }
        $out .= '</div>';
        return $out;
    }

    protected function mapEventRows(array $rows) {
        $mapped = [];
        foreach($rows as $row) {
            $primary = (string) (($row['event_label'] ?? '') ?: ($row['event_name'] ?? 'event'));
            $label = '<strong>' . $this->sanitizer->entities($primary) . '</strong>';
            $meta = (string) ($row['event_group'] ?? 'custom');
            if(!empty($row['event_target'])) $meta .= ' · ' . (string) $row['event_target'];
            $label .= '<br><span class="pwna-muted pwna-break">' . $this->sanitizer->entities($meta) . '</span>';
            $mapped[] = [$label, number_format((int) ($row['events'] ?? 0)), number_format((int) ($row['uniques'] ?? 0)), number_format((int) ($row['sessions'] ?? 0))];
        }
        return $mapped;
    }

    protected function mapEventRowsCompact(array $rows) {
        $mapped = [];
        foreach($rows as $row) {
            $primary = (string) (($row['event_label'] ?? '') ?: ($row['event_name'] ?? 'event'));
            $label = '<strong>' . $this->sanitizer->entities($primary) . '</strong>';
            if(!empty($row['event_target'])) $label .= '<br><span class="pwna-muted pwna-break">' . $this->sanitizer->entities((string) $row['event_target']) . '</span>';
            $mapped[] = [$label, number_format((int) ($row['events'] ?? 0)), number_format((int) ($row['sessions'] ?? 0))];
        }
        return $mapped;
    }

    protected function mapEventTargetRows(array $rows) {
        $mapped = [];
        foreach($rows as $row) {
            $mapped[] = [
                '<span class="pwna-break">' . $this->sanitizer->entities((string) ($row['event_target'] ?? '—')) . '</span>',
                $this->sanitizer->entities((string) ($row['event_group'] ?? 'custom')),
                number_format((int) ($row['events'] ?? 0)),
                number_format((int) ($row['sessions'] ?? 0)),
            ];
        }
        return $mapped;
    }



    protected function renderHelperToolsScript() {
        return <<<'HTML'
<script>
(function(){
  function escAttr(v){
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  function safeEvent(v){
    v = String(v == null ? '' : v).trim().toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_\-]/g, '');
    return v || 'cta_custom';
  }
  function copyText(text){
    if(navigator.clipboard && navigator.clipboard.writeText){
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function(resolve, reject){
      try {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        resolve();
      } catch(err) {
        reject(err);
      }
    });
  }
  function initCopyButtons(){
    document.querySelectorAll('.pwna-copy-btn').forEach(function(btn){
      if(btn.dataset.pwnaInitCopy === '1') return;
      btn.dataset.pwnaInitCopy = '1';
      btn.addEventListener('click', function(){
        var sel = btn.getAttribute('data-pwna-copy-target');
        var target = sel ? document.querySelector(sel) : null;
        if(!target) return;
        var code = target.querySelector('code');
        var text = (code ? code.textContent : target.textContent) || '';
        var original = btn.textContent;
        copyText(text.trim()).then(function(){
          btn.textContent = 'Copied';
          setTimeout(function(){ btn.textContent = original; }, 1200);
        }).catch(function(){
          btn.textContent = 'Failed';
          setTimeout(function(){ btn.textContent = original; }, 1200);
        });
      });
    });
  }
  function initGenerators(){
    document.querySelectorAll("[data-pwna-generator='1']").forEach(function(box){
      if(box.dataset.pwnaInitGen === '1') return;
      box.dataset.pwnaInitGen = '1';
      function field(name){
        return box.querySelector("[data-pwna-gen='" + name + "']");
      }
      function build(){
        var element = (field('element') && field('element').value) || 'a';
        var text = (field('text') && field('text').value) || 'CTA';
        var href = (field('href') && field('href').value) || '/kontakt/';
        var buttonType = (field('button-type') && field('button-type').value) || 'button';
        var eventName = safeEvent(field('event') && field('event').value);
        var group = ((field('group') && field('group').value) || 'custom').trim() || 'custom';
        var label = ((field('label') && field('label').value) || text).trim() || text;
        var attrs = ' data-pwna-event="' + escAttr(eventName) + '" data-pwna-group="' + escAttr(group) + '" data-pwna-label="' + escAttr(label) + '"';
        var snippet = '';
        if(element === 'button') {
          snippet = '<button type="' + escAttr(buttonType) + '"' + attrs + '>' + text + '</button>';
        } else {
          snippet = '<a href="' + escAttr(href) + '"' + attrs + '>' + text + '</a>';
        }
        var out = box.querySelector('#pwna-generated-code code');
        if(out) out.textContent = snippet;
      }
      box.querySelectorAll('input,select').forEach(function(el){
        el.addEventListener('input', build);
        el.addEventListener('change', build);
      });
      var genBtn = box.querySelector("[data-pwna-generate='1']");
      if(genBtn) genBtn.addEventListener('click', build);
      build();
    });
  }
  function getStore(){
    try { return window.sessionStorage; } catch(e) { return null; }
  }
  function readStored(key) {
    var store = getStore();
    if(!store || !key) return '';
    try { return String(store.getItem(key) || ''); } catch(e) { return ''; }
  }
  function writeStored(key, value) {
    var store = getStore();
    if(!store || !key) return;
    try { store.setItem(key, String(value || '')); } catch(e) {}
  }
  function applyEngagementView(view){
    view = view === 'helper' ? 'helper' : 'metrics';
    document.querySelectorAll('[data-pwna-engage-switch]').forEach(function(link){
      var active = link.getAttribute('data-pwna-engage-switch') === view;
      link.classList.toggle('is-active', active);
      link.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    document.querySelectorAll('[data-pwna-engage-panel]').forEach(function(panel){
      panel.hidden = panel.getAttribute('data-pwna-engage-panel') !== view;
    });
  }
  function updateUrlParam(name, value) {
    if(!window.history || !window.history.replaceState) return;
    try {
      var url = new URL(window.location.href);
      if(value) url.searchParams.set(name, value);
      else url.searchParams.delete(name);
      window.history.replaceState({}, document.title, url.toString());
    } catch(e) {}
  }
  function initEngagementSwitch(){
    var explicit = '';
    try {
      explicit = new URL(window.location.href).searchParams.get('engage_view') || '';
    } catch(e) {}
    var initial = explicit || readStored('pwna:engagement-view') || 'metrics';
    applyEngagementView(initial);
    document.querySelectorAll('[data-pwna-engage-input="1"]').forEach(function(input){ input.value = initial; });
    document.querySelectorAll('[data-pwna-engage-switch]').forEach(function(btn){
      if(btn.dataset.pwnaInitEngage === '1') return;
      btn.dataset.pwnaInitEngage = '1';
      btn.addEventListener('click', function(){
        var view = btn.getAttribute('data-pwna-engage-switch') || 'metrics';
        writeStored('pwna:engagement-view', view);
        updateUrlParam('tab', 'engagement');
        updateUrlParam('engage_view', view === 'helper' ? 'helper' : 'metrics');
        document.querySelectorAll('[data-pwna-engage-input="1"]').forEach(function(input){ input.value = view; });
        applyEngagementView(view);
      });
    });
  }
  function initAdaptiveTooltips(){
    var tip = document.getElementById('pwna-floating-tip');
    if(!tip){
      tip = document.createElement('div');
      tip.id = 'pwna-floating-tip';
      tip.className = 'pwna-floating-tip';
      tip.hidden = true;
      tip.innerHTML = '<div class="pwna-floating-tip-inner"></div><div class="pwna-floating-tip-arrow"></div>';
      document.body.appendChild(tip);
    }
    var inner = tip.querySelector('.pwna-floating-tip-inner');
    var active = null;
    function getHtml(el){
      if(!el) return '';
      if(el.classList.contains('pwna-help-tip')){
        var bubble = el.querySelector('.pwna-help-tip-bubble');
        return bubble ? bubble.innerHTML : '';
      }
      if(el.classList.contains('pwna-button-tip')){
        return escAttr(el.getAttribute('data-pwna-tip') || '').replace(/\r\n|\r|\n/g, '<br>');
      }
      return '';
    }
    function pickSide(rect, tw, th){
      var margin = 12;
      var spaces = {
        top: rect.top - margin,
        bottom: window.innerHeight - rect.bottom - margin,
        left: rect.left - margin,
        right: window.innerWidth - rect.right - margin
      };
      if(spaces.bottom >= th + 14) return 'bottom';
      if(spaces.top >= th + 14) return 'top';
      if(spaces.right >= tw + 14) return 'right';
      if(spaces.left >= tw + 14) return 'left';
      var best = 'bottom';
      var bestVal = spaces.bottom;
      Object.keys(spaces).forEach(function(key){ if(spaces[key] > bestVal){ best = key; bestVal = spaces[key]; } });
      return best;
    }
    function place(){
      if(!active || tip.hidden) return;
      var rect = active.getBoundingClientRect();
      var margin = 8;
      var tw = tip.offsetWidth;
      var th = tip.offsetHeight;
      var side = pickSide(rect, tw, th);
      var left = 0, top = 0, arrowX = tw / 2, arrowY = th / 2;
      if(side === 'bottom' || side === 'top'){
        left = rect.left + (rect.width / 2) - (tw / 2);
        left = Math.max(margin, Math.min(left, window.innerWidth - tw - margin));
        arrowX = Math.max(14, Math.min((rect.left + rect.width / 2) - left, tw - 14));
        top = side === 'bottom' ? rect.bottom + 12 : rect.top - th - 12;
        top = Math.max(margin, Math.min(top, window.innerHeight - th - margin));
      } else {
        top = rect.top + (rect.height / 2) - (th / 2);
        top = Math.max(margin, Math.min(top, window.innerHeight - th - margin));
        arrowY = Math.max(14, Math.min((rect.top + rect.height / 2) - top, th - 14));
        left = side === 'right' ? rect.right + 12 : rect.left - tw - 12;
        left = Math.max(margin, Math.min(left, window.innerWidth - tw - margin));
      }
      tip.dataset.side = side;
      tip.style.left = left + 'px';
      tip.style.top = top + 'px';
      tip.style.setProperty('--pwna-arrow-x', arrowX + 'px');
      tip.style.setProperty('--pwna-arrow-y', arrowY + 'px');
    }
    function show(el){
      var html = getHtml(el);
      if(!html) return;
      active = el;
      inner.innerHTML = html;
      tip.hidden = false;
      el.classList.add('pwna-tip-managed');
      place();
    }
    function hide(el){
      if(el) el.classList.add('pwna-tip-managed');
      if(active && el && active !== el) return;
      active = null;
      tip.hidden = true;
      tip.removeAttribute('data-side');
    }
    function bind(el){
      if(!el || el.dataset.pwnaTipInit === '1') return;
      el.dataset.pwnaTipInit = '1';
      el.classList.add('pwna-tip-managed');
      el.addEventListener('mouseenter', function(){ show(el); });
      el.addEventListener('focus', function(){ show(el); });
      el.addEventListener('mouseleave', function(){ hide(el); });
      el.addEventListener('blur', function(){ hide(el); });
    }
    document.querySelectorAll('.pwna-help-tip, .pwna-button-tip').forEach(bind);
    window.addEventListener('scroll', place, {passive:true});
    window.addEventListener('resize', place);
  }
  function init(){
    initCopyButtons();
    initGenerators();
    initEngagementSwitch();
    initAdaptiveTooltips();
  }
  if(document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
</script>
HTML;
    }

    protected function buildFilterLabel(array $filters) {
        $parts = [];
        if(!empty($filters['page_id'])) $parts[] = 'page ID ' . (int) $filters['page_id'];
        if(!empty($filters['template'])) $parts[] = 'template ' . (string) $filters['template'];
        return $parts ? implode(', ', $parts) : 'All pages and templates';
    }

    protected function relativeTime($datetime) {
        $ts = strtotime((string) $datetime);
        if(!$ts) return '—';
        $diff = max(0, time() - $ts);
        if($diff < 60) return $diff . 's ago';
        if($diff < 3600) return floor($diff / 60) . 'm ago';
        return floor($diff / 3600) . 'h ago';
    }
}
