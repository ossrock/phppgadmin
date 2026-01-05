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
     * @param string|null $exe_path Optional path to external dump utility (e.g., pg_dump)
     * @param string|null $version Optional version of the utility
     */
    public static function beginHtmlOutput($exe_path = null, $version = null)
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
            <input type="button" value="🔙 Back" onclick="history.back()">
            <input type="button" value="🔄 Reload" onclick="location.reload()">
            <input type="button" value="✨ Format" onclick="createSqlEditor(document.getElementById('export-output'))">
        </div>
        <?php
        echo "<textarea id=\"export-output\" class=\"export-output\" readonly>";
        if ($exe_path && $version) {
            echo "-- Dumping with " . htmlspecialchars($exe_path) . " version " . $version . "\n\n";
        }
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
            <input type="button" value="🔙 Back" onclick="history.back()">
            <input type="button" value="🔄 Reload" onclick="location.reload()">
            <input type="button" value="✨ Format" onclick="createSqlEditor(document.getElementById('export-output'))">
        </div>
        <?php
        $misc = AppContainer::getMisc();
        $misc->printFooter();
    }


}
