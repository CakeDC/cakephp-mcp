<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class Html
{
    /**
     * The hidden inputs of the first form containing $marker in a field name (all forms when null).
     *
     * @param string $html The page.
     * @param string|null $marker A hidden field name the form must contain.
     * @return array<string, string>
     */
    public static function hiddenFields(string $html, ?string $marker = null): array
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($document);
        $fields = [];
        foreach ($xpath->query('//form') ?: [] as $form) {
            if (!$form instanceof DOMElement) {
                continue;
            }
            $inputs = [];
            foreach ($xpath->query('.//input[@type="hidden"]', $form) ?: [] as $input) {
                if ($input instanceof DOMElement) {
                    $inputs[$input->getAttribute('name')] = $input->getAttribute('value');
                }
            }
            if ($marker === null) {
                $fields += $inputs;
            } elseif (isset($inputs[$marker])) {
                return $inputs;
            }
        }

        return $fields;
    }
}
