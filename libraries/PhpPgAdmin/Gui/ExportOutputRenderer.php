<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AppContainer;

/**
 * Unified export output rendering helper.
 * Provides consistent "show in browser" HTML UI for both database and query exports.
 * Eliminates code duplication between dbexport.php and dataexport.php.
 */
class ExportOutputRenderer
{
    /**
     * Start HTML output for "show in browser" mode.
     * Renders header, navigation, and opens textarea container.
     *
     * @param array|null $options Optional options array
     */
    public static function beginHtmlOutput($options = null)
    {
        AppContainer::setSkipHtmlFrame(false);
        $misc = AppContainer::getMisc();
        $subject = $_REQUEST['subject'] ?? 'server';
        $misc->printHeader("Export", null);
        $misc->printBody();
        $misc->printTrail($subject);
        $misc->printTabs($subject, 'export');

        ?>
        <div class="mb-2">
            <input class="ui-btn" type="button" value="🔙 Back" onclick="history.back()">
            <input class="ui-btn" type="button" value="🔄 Reload" onclick="location.reload()">
            <input class="ui-btn" type="button" value="✨ Highlight"
                onclick="createSqlEditor(document.getElementById('export-output'))">
        </div>
        <?php
        $modeAttr = isset($options['mode']) ? " data-mode=\"{$options['mode']}\"" : '';
        echo "<textarea id=\"export-output\" class=\"export-output\"$modeAttr>";
        /*
        if ($options && isset($options['exe_path']) && isset($options['version'])) {
            echo "-- Dumping with " . htmlspecialchars($options['exe_path']) . " version " . $options['version'] . "\n\n";
        }
        */
    }

    /**
     * End HTML output for "show in browser" mode.
     * Closes textarea and renders footer controls.
     */
    public static function endHtmlOutput()
    {
        echo "</textarea>\n";
        ?>
        <div class="my-2">
            <input class="ui-btn" type="button" value="🔙 Back" onclick="history.back()">
            <input class="ui-btn" type="button" value="🔄 Reload" onclick="location.reload()">
            <input class="ui-btn" type="button" value="✨ Highlight"
                onclick="createSqlEditor(document.getElementById('export-output'))">
        </div>
        <?php
        $misc = AppContainer::getMisc();
        $misc->printFooter();
    }


}
