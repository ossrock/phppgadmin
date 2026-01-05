<?php

namespace PhpPgAdmin\Database\Export;

/**
 * XML Format Formatter
 * Converts table data to XML with structure and data
 */
class XmlFormatter extends OutputFormatter
{
    /** @var string */
    protected $mimeType = 'text/xml; charset=utf-8';
    /** @var string */
    protected $fileExtension = 'xml';
    /** @var bool */
    protected $supportsGzip = true;

    /**
     * Format ADORecordSet as XML
     * @param \ADORecordSet $recordset ADORecordSet
     * @param array $metadata Optional (unused, columns come from recordset)
     */
    public function format($recordset, $metadata = [])
    {
        $this->write('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        $this->write("<data>\n");

        if (!$recordset || $recordset->EOF) {
            $this->write("</data>\n");
            return;
        }

        // Get column information from recordset fields
        $columns = [];
        $fieldIndex = 0;
        foreach ($recordset->fields as $fieldName => $fieldValue) {
            $finfo = $recordset->fetchField($fieldIndex);
            $type = $finfo->type ?? 'unknown';

            $columns[$fieldIndex] = [
                'name' => $finfo->name ?? $fieldName,
                'type' => $type
            ];
            $fieldIndex++;
        }

        // Write header with column information
        $this->write("<header>\n");
        foreach ($columns as $col) {
            $name = $this->xmlEscape($col['name']);
            $type = $this->xmlEscape($col['type']);
            $this->write("\t<col name=\"{$name}\" type=\"{$type}\" />\n");
        }
        $this->write("</header>\n");

        // Write records section
        $this->write("<records>\n");
        while (!$recordset->EOF) {
            $this->write("\t<row>\n");
            $i = 0;
            foreach ($recordset->fields as $fieldValue) {
                if (isset($columns[$i])) {
                    $col_name = $this->xmlEscape($columns[$i]['name']);
                    $value = $fieldValue;
                    if (!is_null($value)) {
                        $value = $this->xmlEscape($value);
                    }
                    $this->write("\t\t<col name=\"{$col_name}\"" . (is_null($value) ? ' null="null"' : '') . ">{$value}</col>\n");
                }
                $i++;
            }
            $this->write("\t</row>\n");
            $recordset->moveNext();
        }
        $this->write("</records>\n");

        $this->write("</data>\n");
    }

    /**
     * XML-escape string
     */
    private function xmlEscape($str)
    {
        return htmlspecialchars($str, ENT_XML1, 'UTF-8');
    }
}
