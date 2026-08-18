<?php

namespace justinholtweb\book\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\Markdown;
use HTMLPurifier;
use HTMLPurifier_Config;
use justinholtweb\book\models\DocumentOptions;
use justinholtweb\book\models\Source;
use justinholtweb\book\Plugin;
use Throwable;

/**
 * Turns a text-shaped file into HTML, on the server, with nobody else involved.
 *
 * This is the part of Book that has no equivalent in the plugins it is modelled on, and the
 * reason is worth stating: a CSV does not need a document viewer, it needs a `<table>`. Same for
 * a Markdown file, a JSON export, a log. Handing those to Google is both slower and worse.
 *
 * **Assets only.** The inliner reads bytes through `Asset::getContents()`, which goes to the
 * volume's own filesystem. Book never opens an outbound HTTP connection, so an external URL
 * cannot be inlined — {@see Viewers::rejectionReason()} says so rather than trying.
 */
class Inliner extends Component
{
    /**
     * @return array{0: string|null, 1: string|null} The HTML, or an explanation of why not.
     */
    public function render(Source $source, DocumentOptions $options): array
    {
        $format = $source->getFormat();
        $settings = Plugin::getInstance()->getSettings();

        if (!$format->getIsInlineable()) {
            return [null, Craft::t('book', 'Book cannot render a {format} itself.', [
                'format' => mb_strtolower($format->name),
            ])];
        }

        if (!$source->getIsReadable()) {
            return [null, Craft::t('book', 'Book can only render a file it holds as an asset.')];
        }

        if ($source->size !== null && $source->size > $settings->inlineMaxBytes) {
            return [null, Craft::t('book', 'This file is larger than Book’s inline limit.')];
        }

        $contents = $source->getContents();

        if ($contents === null) {
            return [null, Craft::t('book', 'Book could not read the file.')];
        }

        // Belt and braces: `size` is what the index says, and an index can be stale.
        if (strlen($contents) > $settings->inlineMaxBytes) {
            $contents = substr($contents, 0, $settings->inlineMaxBytes);
        }

        $contents = $this->toUtf8($contents);

        try {
            return [match ($format->inlineAs) {
                'csv' => $this->renderCsv($contents, $source, $options),
                'markdown' => $this->renderMarkdown($contents),
                'json' => $this->renderData($contents, $source),
                'code' => $this->renderCode($contents, $source->extension, $options),
                default => $this->renderText($contents, $options),
            }, null];
        } catch (Throwable $e) {
            Craft::warning('Book could not inline ' . $source->filename . ': ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return [null, Craft::t('book', 'Book could not read the file.')];
        }
    }

    // -------------------------------------------------------------------------

    /**
     * A CSV as a table.
     *
     * Delimiter sniffing rather than a setting: the file either has more commas than tabs or it
     * does not, and a `.tsv` that is actually comma-separated is common enough to be worth
     * handling without asking anybody.
     */
    private function renderCsv(string $contents, Source $source, DocumentOptions $options): string
    {
        $delimiter = $this->sniffDelimiter($contents, $source->extension);
        $rows = [];
        $truncated = false;
        $limit = $options->maxRows > 0 ? $options->maxRows : PHP_INT_MAX;

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        // Escape character explicitly empty: RFC 4180 has no backslash escape, PHP's default
        // does, and the difference shows up as a mangled cell in any file containing a path.
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            // fgetcsv gives `[null]` for a blank line, which is not a row of data.
            if ($row === [null]) {
                continue;
            }

            if (count($rows) >= $limit) {
                $truncated = true;
                break;
            }

            $rows[] = $row;
        }

        fclose($handle);

        if (!$rows) {
            return Html::tag('p', Craft::t('book', 'This file has no rows.'), ['class' => 'book-inline__empty']);
        }

        $header = $options->csvHeader ? array_shift($rows) : null;

        $counts = array_map('count', $rows);

        if ($header) {
            $counts[] = count($header);
        }

        $columns = $counts ? max($counts) : 0;

        if ($columns === 0) {
            return Html::tag('p', Craft::t('book', 'This file has no rows.'), ['class' => 'book-inline__empty']);
        }

        $html = '';

        if ($header) {
            $html .= Html::tag('thead', Html::tag('tr', implode('', array_map(
                fn($cell) => Html::tag('th', Html::encode((string)$cell), ['scope' => 'col']),
                $this->pad($header, $columns)
            ))));
        }

        $body = '';

        foreach ($rows as $row) {
            $body .= Html::tag('tr', implode('', array_map(
                fn($cell) => Html::tag('td', Html::encode((string)$cell)),
                $this->pad($row, $columns)
            )));
        }

        $html .= Html::tag('tbody', $body);
        $out = Html::tag('div', Html::tag('table', $html, ['class' => 'book-table']), ['class' => 'book-inline__scroll']);

        if ($truncated) {
            $out .= Html::tag('p', Craft::t('book', 'Showing the first {count} rows.', ['count' => $options->maxRows]), [
                'class' => 'book-inline__note',
            ]);
        }

        return $out;
    }

    /**
     * Markdown, purified.
     *
     * Markdown allows raw HTML by design, and this file came from an asset volume — which is a
     * place uploads land. Purifier is not optional here.
     */
    private function renderMarkdown(string $contents): string
    {
        return Html::tag('div', $this->purify(Markdown::process($contents, 'gfm')), [
            'class' => 'book-inline__prose',
        ]);
    }

    /** JSON pretty-printed; anything else that claims to be data shown as it is. */
    private function renderData(string $contents, Source $source): string
    {
        if (in_array($source->extension, ['json', 'geojson'], true)) {
            $decoded = json_decode($contents, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $contents = Json::encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        return $this->pre($contents, false);
    }

    private function renderCode(string $contents, string $extension, DocumentOptions $options): string
    {
        return $this->pre($contents, $options->wrap, $extension);
    }

    private function renderText(string $contents, DocumentOptions $options): string
    {
        return $this->pre($contents, $options->wrap);
    }

    /**
     * Everything that is not prose ends up here: encoded, never purified, because it is not HTML
     * and pretending otherwise would rewrite the file.
     */
    private function pre(string $contents, bool $wrap, string $language = ''): string
    {
        $classes = ['book-code'];

        if ($wrap) {
            $classes[] = 'book-code--wrap';
        }

        $code = Html::tag('code', Html::encode($contents), $language !== '' ? ['class' => 'language-' . preg_replace('/[^a-z0-9]+/', '', $language)] : []);

        return Html::tag('pre', $code, ['class' => $classes, 'tabindex' => '0']);
    }

    /** @param array<int, mixed> $row @return array<int, mixed> */
    private function pad(array $row, int $columns): array
    {
        return array_pad(array_slice($row, 0, $columns), $columns, '');
    }

    private function sniffDelimiter(string $contents, string $extension): string
    {
        $sample = substr($contents, 0, 4096);
        $tabs = substr_count($sample, "\t");
        $commas = substr_count($sample, ',');
        $semicolons = substr_count($sample, ';');

        if ($tabs > $commas && $tabs > $semicolons) {
            return "\t";
        }

        if ($semicolons > $commas) {
            return ';';
        }

        if ($commas === 0 && $extension === 'tsv') {
            return "\t";
        }

        return ',';
    }

    /**
     * Text off a volume is whatever somebody's laptop produced — a Windows-exported CSV is
     * Windows-1252 far more often than it is UTF-8, and mangled accents are the tell.
     */
    private function toUtf8(string $contents): string
    {
        $bom = "\xEF\xBB\xBF";

        if (str_starts_with($contents, $bom)) {
            return substr($contents, strlen($bom));
        }

        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        return mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
    }

    private function purify(string $html): string
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->autoFinalize = false;

        $options = [
            'Attr.AllowedFrameTargets' => ['_blank', '_self', '_top'],
            'Attr.EnableID' => true,
            // Ids in a Markdown file are only unique within that file; prefixing them keeps
            // in-page anchors working without colliding with the page's own.
            'Attr.IDPrefix' => 'book-',
            'HTML.SafeIframe' => true,
            'URI.SafeIframeRegexp' => '%^https?://%',
            'CSS.AllowTricky' => false,
            'Cache.DefinitionImpl' => null,
        ];

        $file = Plugin::getInstance()->getSettings()->purifierConfig;

        if ($file) {
            $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . 'htmlpurifier' . DIRECTORY_SEPARATOR . $file . '.json';

            if (is_file($path)) {
                $decoded = json_decode((string)file_get_contents($path), true);

                if (is_array($decoded)) {
                    $options = $decoded + $options;
                }
            }
        }

        foreach ($options as $option => $value) {
            $config->set($option, $value);
        }

        // Purifier writes its serialized definitions somewhere, and its default is inside its
        // own vendor directory — read-only on plenty of deploys, and noisy when it is.
        $cachePath = Craft::$app->getPath()->getRuntimePath() . DIRECTORY_SEPARATOR . 'book' . DIRECTORY_SEPARATOR . 'purifier';

        try {
            FileHelper::createDirectory($cachePath);
            $config->set('Cache.SerializerPath', $cachePath);
        } catch (Throwable) {
            // A read-only runtime directory costs speed, not correctness.
        }

        return (new HTMLPurifier($config))->purify($html);
    }
}
