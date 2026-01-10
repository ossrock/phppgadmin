<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AbstractContext;
use PhpPgAdmin\Database\Export\Compression\CompressionFactory;
use PhpPgAdmin\Database\Actions\RoleActions;

class ImportFormRenderer extends AbstractContext
{
    public function renderImportForm(string $scope, array $options = []): void
    {
        $lang = $this->lang();
        $conf = $this->conf();
        $misc = $this->misc();
        $importCfg = $conf['import'] ?? [];
        $maxSize = (int) ($importCfg['upload_max_size'] ?? 0);
        $chunkSize = (int) ($importCfg['upload_chunk_size'] ?? 2 * 1024 * 1024);
        $maxAttr = $maxSize > 0 ? 'data-import-max-size="' . htmlspecialchars((string) $maxSize) . '"' : '';
        $chunkAttr = $chunkSize > 0 ? 'data-import-chunk-size="' . htmlspecialchars((string) $chunkSize) . '"' : '';

        $caps = CompressionFactory::capabilities();
        $capsAttr = sprintf(
            'data-cap-gzip="%s" data-cap-zip="%s" data-cap-bzip2="%s"',
            $caps['gzip'] ? '1' : '0',
            $caps['zip'] ? '1' : '0',
            $caps['bzip2'] ? '1' : '0'
        );
        // determine if current user is superuser to show admin controls
        $pg = $this->postgres();
        $roleActions = new RoleActions($pg);
        $isSuper = $roleActions->isSuperUser();
        ?>
        <form id="importForm" method="post" enctype="multipart/form-data" action="#">
            <input type="hidden" name="scope" id="import_scope" value="<?= htmlspecialchars($scope) ?>" />
            <input type="hidden" name="scope_ident" id="import_scope_ident"
                value="<?= htmlspecialchars($options['scope_ident'] ?? '') ?>" />
            <?= $misc->form ?>

            <fieldset>
                <legend><?= $lang['struploadfile'] ?></legend>
                <input type="file" name="file" id="file" <?= $capsAttr ?>         <?= $maxAttr ?>         <?= $chunkAttr ?> />
                <div id="importCompressionCaps" style="margin-top:6px">
                    <strong><?= $lang['strimportcompressioncaps'] ?? 'Compression support' ?>:</strong> gzip, bzip2, zip
                </div>
                <div class="info mt-2">
                    <?= $lang['strimportintro'] ?? 'The import function reads and unpacks a file in the browser chunk by chunk and uploads the parts, where they are processed immediately. With this logic, it should be possible to run long imports and import large files.' ?>
                </div>
            </fieldset>

            <fieldset>
                <legend><?= $lang['stroptions'] ?></legend>
                <?php if ($scope === 'server'): ?>
                    <div><label><input type="checkbox" name="opt_roles" checked /> <?= $lang['strimportroles'] ?></label></div>
                    <div><label><input type="checkbox" name="opt_tablespaces" checked />
                            <?= $lang['strimporttablespaces'] ?></label></div>
                    <div><label><input type="checkbox" name="opt_databases" checked />
                            <?= $lang['strimportdatabases'] ?? 'Import databases' ?></label></div>
                <?php endif; ?>
                <?php if ($scope === 'database' || $scope === 'server'): ?>
                    <div><label><input type="checkbox" name="opt_schema_create" checked />
                            <?= $lang['strcreateschema'] ?></label></div>
                <?php endif; ?>
                <div><label><input type="checkbox" name="opt_data" checked />
                        <?= $lang['strimportdata'] ?? 'Import data (COPY/INSERT)' ?></label></div>
                <?php if ($scope === 'schema' || $scope === 'table'): ?>
                    <div><label><input type="checkbox" name="opt_truncate" /> <?= $lang['strtruncatebefore'] ?></label></div>
                <?php endif; ?>
                <div><label><input type="checkbox" name="opt_ownership" checked />
                        <?= $lang['strimportownership'] ?? 'Apply ownership (ALTER ... OWNER)' ?></label></div>
                <div><label><input type="checkbox" name="opt_rights" checked />
                        <?= $lang['strimportrights'] ?? 'Apply rights (GRANT/REVOKE)' ?></label></div>
                <div><label><input type="checkbox" name="opt_defer_self" checked /> <?= $lang['strdeferself'] ?></label></div>
                <div><label><input type="checkbox" name="opt_allow_drops" />
                        <?= $lang['strimportallowdrops'] ?? 'Allow DROP statements' ?></label></div>
                <?php if (!empty($caps['gzip'])): ?>
                    <div style="margin-top:8px"><label><input type="checkbox" name="opt_compress_chunks" />
                            <?= $lang['strimportcompresschunks'] ?? 'Compress chunks with gzip (saves bandwidth)' ?></label></div>
                <?php endif; ?>
            </fieldset>

            <fieldset>
                <legend><?= $lang['strerrorhandling'] ?? 'Error handling' ?></legend>
                <div><label><input type="radio" name="opt_error_mode" value="abort" checked />
                        <?= $lang['strimporterrorabort'] ?? 'Abort on first error' ?></label></div>
                <div><label><input type="radio" name="opt_error_mode" value="log" />
                        <?= $lang['strimporterrorlog'] ?? 'Log errors and continue' ?></label></div>
                <div><label><input type="radio" name="opt_error_mode" value="ignore" />
                        <?= $lang['strimporterrorignore'] ?? 'Ignore errors (not recommended)' ?></label></div>
                <div style="margin-top:6px"><label>
                        <?= $lang['strskipstatements'] ?? 'Skip statements' ?>:
                        <input type="number" name="skip_statements" value="0" min="0" style="width:80px" />
                    </label></div>
            </fieldset>

            <div class="form-group">
                <button type="button" id="importStart"><?= $lang['strupload'] ?></button>
            </div>
        </form>

        <div id="importUI" style="display:none;margin-top:16px">
            <h4><?= $lang['strimport'] ?> <span id="importJobTitle"
                    style="font-weight:normal;font-size:0.9em;color:#555"></span> -
                <?= $lang['strprogress'] ?? 'Progress' ?>
            </h4>
            <progress id="importProgress" value="0" max="100" style="width:100%"></progress>
            <div id="importStatus" style="margin-top:4px;font-size:0.9em;color:#666"></div>
            <div style="margin-top:8px">
                <button id="importStopBtn" type="button" style="display:none">
                    <?= $lang['strstop'] ?? 'Stop' ?>
                </button>
            </div>
            <pre id="importLog"
                style="height:200px;overflow:auto;border:1px solid #ccc;padding:6px;margin-top:8px;background:#f9f9f9"></pre>
        </div>

        <script type="module" src="js/import/stream_upload.js"></script>
        <?php
    }

    public function renderDataImportForm(string $scope, array $options = []): void
    {
        $lang = $this->lang();
        $misc = $this->misc();
        ?>
        <form action="dataimport.php" method="post" enctype="multipart/form-data">
            <table>
                <tr>
                    <th class="data left required"><?= $lang['strformat'] ?></th>
                    <td><select name="format">
                            <option value="auto"><?= $lang['strauto'] ?></option>
                            <option value="csv">CSV</option>
                            <option value="tab"><?= $lang['strtabbed'] ?></option>
                            <?php if (function_exists('xml_parser_create')): ?>
                                <option value="xml">XML</option>
                            <?php endif; ?>
                        </select></td>
                </tr>
                <tr>
                    <th class="data left required"><?= $lang['strallowednulls'] ?></th>
                    <td>
                        <label><input type="checkbox" name="allowednulls[0]" value="\N"
                                checked="checked" /><?= $lang['strbackslashn'] ?></label><br />
                        <label><input type="checkbox" name="allowednulls[1]" value="NULL" />NULL</label><br />
                        <label><input type="checkbox" name="allowednulls[2]" value="" /><?= $lang['stremptystring'] ?></label>
                    </td>
                </tr>
                <tr>
                    <th class="data left required"><?= $lang['strfile'] ?></th>
                    <td><input type="file" name="source" /></td>
                </tr>
            </table>
            <p><input type="hidden" name="action" value="import" />
                <?= $misc->form ?>
                <input type="hidden" name="table" value="<?= html_esc($_REQUEST['table']) ?>" />
                <input type="submit" value="<?= $lang['strimport'] ?>" />
            </p>
        </form>
        <?php
    }
}
