<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Accessibility;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * A deterministic, parser-level WCAG 2.1 AA auditor.
 *
 * It loads rendered HTML and reports the machine-checkable violations — missing img alt, unlabelled form
 * controls, links/buttons with no accessible name, missing page language/title, no skip link, no h1,
 * positive tabindex, and broken label/aria id references. It does NOT judge colour contrast, focus order
 * quality or screen-reader experience — those are not derivable from static HTML and live in the manual
 * checklist. Zero findings here is a floor, not a guarantee of full conformance.
 *
 * Used two ways: the Pest gate audits rendered pages and asserts zero findings; the `novfora:a11y:audit`
 * command runs the same engine ad hoc.
 */
final class AccessibilityAuditor
{
    /** @var list<Finding> */
    private array $findings = [];

    private DOMXPath $xpath;

    /**
     * @return list<Finding>
     */
    public function audit(string $html, bool $isFullPage = true): array
    {
        $this->findings = [];

        if (trim($html) === '') {
            return [];
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // Force UTF-8 and parse as an HTML fragment-tolerant document; suppress HTML5 tag warnings.
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->xpath = new DOMXPath($doc);

        if ($isFullPage) {
            $this->checkDocumentLanguage();
            $this->checkPageTitle();
            $this->checkSingleMainAndHeadings();
            $this->checkSkipLink();
        }

        $this->checkImages();
        $this->checkFormControls();
        $this->checkLinkAndButtonNames();
        $this->checkPositiveTabindex();
        $this->checkLabelReferences();

        return $this->findings;
    }

    private function add(string $rule, string $message, string $level, DOMElement|string $context = ''): void
    {
        $snippet = $context instanceof DOMElement ? $this->snippet($context) : $context;
        $this->findings[] = new Finding($rule, $level, $message, $snippet);
    }

    // ── document-level ───────────────────────────────────────────────────────────────────────────────────

    private function checkDocumentLanguage(): void
    {
        $html = $this->xpath->query('//html')->item(0);
        if ($html instanceof DOMElement && trim($html->getAttribute('lang')) === '') {
            $this->add('3.1.1', 'The <html> element has no non-empty lang attribute.', 'A');
        }
    }

    private function checkPageTitle(): void
    {
        $title = $this->xpath->query('//head/title')->item(0);
        if ($title === null || trim($title->textContent) === '') {
            $this->add('2.4.2', 'The document has no non-empty <title>.', 'A');
        }
    }

    private function checkSingleMainAndHeadings(): void
    {
        $mains = $this->xpath->query('//main | //*[@role="main"]');
        if ($mains->length === 0) {
            $this->add('1.3.1', 'No <main> landmark (or role="main") on the page.', 'A');
        } elseif ($mains->length > 1) {
            $this->add('1.3.1', "Multiple main landmarks ({$mains->length}); there must be exactly one.", 'A');
        }

        if ($this->xpath->query('//h1')->length === 0) {
            $this->add('1.3.1', 'The page has no <h1> heading.', 'AA');
        }
    }

    private function checkSkipLink(): void
    {
        // A bypass mechanism: any in-page anchor whose target id exists, or an element carrying a skip class.
        $anchors = $this->xpath->query('//a[starts-with(@href, "#")]');
        foreach ($anchors as $a) {
            if (! $a instanceof DOMElement) {
                continue;
            }
            $targetId = ltrim($a->getAttribute('href'), '#');
            if ($targetId !== '' && $this->xpath->query('//*[@id="'.$this->escape($targetId).'"]')->length > 0) {
                return; // a working in-page jump link exists
            }
        }

        if ($this->xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " skip-link ")]')->length > 0) {
            return;
        }

        $this->add('2.4.1', 'No skip link / bypass-blocks mechanism found.', 'A');
    }

    // ── element-level ────────────────────────────────────────────────────────────────────────────────────

    private function checkImages(): void
    {
        foreach ($this->xpath->query('//img') as $img) {
            if (! $img instanceof DOMElement) {
                continue;
            }
            if ($this->isHidden($img)) {
                continue;
            }
            // alt MUST be present (it may be empty="" to mark the image decorative — that is valid).
            if (! $img->hasAttribute('alt')) {
                $this->add('1.1.1', 'An <img> has no alt attribute (use alt="" if decorative).', 'A', $img);
            }
        }
    }

    private function checkFormControls(): void
    {
        foreach ($this->xpath->query('//input | //select | //textarea') as $el) {
            if (! $el instanceof DOMElement || $this->isHidden($el)) {
                continue;
            }
            $type = strtolower($el->getAttribute('type'));

            if ($el->nodeName === 'input' && in_array($type, ['hidden', 'submit', 'button', 'reset'], true)) {
                // Names for these come from value/text, covered by the button-name check below.
                continue;
            }
            if ($el->nodeName === 'input' && $type === 'image') {
                if (trim($el->getAttribute('alt')) === '' && ! $this->hasAriaName($el)) {
                    $this->add('1.1.1', 'An image button (<input type="image">) has no alt/aria-label.', 'A', $el);
                }

                continue;
            }

            if (! $this->hasAccessibleLabel($el)) {
                $this->add('4.1.2', "A form control (<{$el->nodeName}>) has no associated label or aria name.", 'A', $el);
            }
        }
    }

    private function checkLinkAndButtonNames(): void
    {
        // Links that actually navigate, plus all buttons and button-like inputs.
        $nodes = $this->xpath->query('//a[@href] | //button | //input[@type="submit" or @type="button" or @type="reset"]');
        foreach ($nodes as $el) {
            if (! $el instanceof DOMElement || $this->isHidden($el)) {
                continue;
            }

            if ($el->nodeName === 'input') {
                $name = trim($el->getAttribute('value'));
                if ($name === '' && ! $this->hasAriaName($el)) {
                    $this->add('4.1.2', 'A button input has no value/aria name.', 'A', $el);
                }

                continue;
            }

            if ($this->accessibleText($el) !== '' || $this->hasAriaName($el)) {
                continue;
            }
            // An <img alt> or titled <svg> descendant also names the control.
            if ($this->hasNamedGraphicChild($el)) {
                continue;
            }

            $what = $el->nodeName === 'a' ? 'A link (<a href>)' : 'A <button>';
            $this->add('4.1.2', "{$what} has no accessible name (text, aria-label, or labelled icon).", 'AA', $el);
        }
    }

    private function checkPositiveTabindex(): void
    {
        foreach ($this->xpath->query('//*[@tabindex]') as $el) {
            if (! $el instanceof DOMElement) {
                continue;
            }
            if ((int) $el->getAttribute('tabindex') > 0) {
                $this->add('2.4.3', 'A positive tabindex distorts the natural focus order.', 'A', $el);
            }
        }
    }

    private function checkLabelReferences(): void
    {
        // A <label for> / aria-labelledby / aria-describedby pointing at a non-existent id is a broken name.
        $checks = [
            ['//label[@for]', 'for', '1.3.1', 'A'],
            ['//*[@aria-labelledby]', 'aria-labelledby', '4.1.2', 'A'],
            ['//*[@aria-describedby]', 'aria-describedby', '1.3.1', 'A'],
        ];
        foreach ($checks as [$query, $attr, $criterion, $level]) {
            foreach ($this->xpath->query($query) as $el) {
                if (! $el instanceof DOMElement) {
                    continue;
                }
                foreach (preg_split('/\s+/', trim($el->getAttribute($attr))) ?: [] as $id) {
                    if ($id !== '' && $this->xpath->query('//*[@id="'.$this->escape($id).'"]')->length === 0) {
                        $this->add($criterion, "{$attr} references a missing id \"{$id}\".", $level, $el);
                    }
                }
            }
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────

    private function hasAccessibleLabel(DOMElement $el): bool
    {
        if ($this->hasAriaName($el)) {
            return true;
        }
        if (trim($el->getAttribute('title')) !== '') {
            return true;
        }
        // Explicit association: <label for="id">.
        $id = $el->getAttribute('id');
        if ($id !== '' && $this->xpath->query('//label[@for="'.$this->escape($id).'"]')->length > 0) {
            return true;
        }
        // Implicit association: wrapped in a <label> that carries text.
        $ancestorLabel = $this->xpath->query('ancestor::label', $el)->item(0);
        if ($ancestorLabel !== null && trim($ancestorLabel->textContent) !== '') {
            return true;
        }

        return false;
    }

    private function hasAriaName(DOMElement $el): bool
    {
        return trim($el->getAttribute('aria-label')) !== '' || trim($el->getAttribute('aria-labelledby')) !== '';
    }

    private function hasNamedGraphicChild(DOMElement $el): bool
    {
        foreach ($this->xpath->query('.//img[@alt]', $el) as $img) {
            if ($img instanceof DOMElement && trim($img->getAttribute('alt')) !== '') {
                return true;
            }
        }
        foreach ($this->xpath->query('.//*[local-name()="svg"]', $el) as $svg) {
            if ($svg instanceof DOMElement
                && ($this->hasAriaName($svg) || $this->xpath->query('.//*[local-name()="title"]', $svg)->length > 0)) {
                return true;
            }
        }

        return false;
    }

    /** Accessible text = trimmed, whitespace-collapsed textContent (includes visually-hidden sr-only text). */
    private function accessibleText(DOMElement $el): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $el->textContent));
    }

    private function isHidden(DOMElement $el): bool
    {
        if ($el->getAttribute('aria-hidden') === 'true' || $el->hasAttribute('hidden')) {
            return true;
        }
        if (str_contains($el->getAttribute('type'), 'hidden')) {
            return true;
        }
        // display:none / visibility:hidden in an inline style.
        $style = strtolower(str_replace(' ', '', $el->getAttribute('style')));

        return str_contains($style, 'display:none') || str_contains($style, 'visibility:hidden');
    }

    private function snippet(DOMElement $el): string
    {
        $html = (string) $el->ownerDocument?->saveHTML($el);
        $html = trim((string) preg_replace('/\s+/', ' ', $html));

        return mb_strlen($html) > 120 ? mb_substr($html, 0, 117).'…' : $html;
    }

    /** Escape a value for safe embedding inside an XPath double-quoted string. */
    private function escape(string $value): string
    {
        return str_replace('"', '', $value);
    }
}
