<?php

declare(strict_types=1);

namespace Huncwot\UhoFramework;

/**
 * Twig rendering used by the ORM for schema-driven strings: url patterns,
 * media filenames, virtual field values.
 *
 * The environment is created lazily - most queries never render anything - and
 * short templates are kept in a small in-memory cache, since the same filename
 * pattern is rendered once per record.
 *
 * The environment is sandboxed. Templates here come from schema files, which
 * are trusted, but the values rendered into them come from the database, and a
 * pattern assembled from both would otherwise let stored text reach the
 * compiler. The policy below allows what schema patterns actually use -
 * formatting filters, no object methods, no properties - so an expression that
 * escapes into method calls fails instead of running.
 */
class _uho_orm2_twig
{
    /** templates below this length are worth caching by content hash */
    private const CACHE_LENGTH_LIMIT = 100;

    /** tags a schema pattern may use */
    private const ALLOWED_TAGS = ['if', 'for', 'set'];

    /** filters a schema pattern may use - none of them takes a callback */
    private const ALLOWED_FILTERS = [
        'abs', 'capitalize', 'date', 'default', 'e', 'escape', 'first', 'format',
        'join', 'json_encode', 'keys', 'last', 'length', 'lower', 'nl2br',
        'number_format', 'raw', 'replace', 'reverse', 'round', 'slice', 'split',
        'striptags', 'title', 'trim', 'upper', 'url_encode',
    ];

    /** functions a schema pattern may call */
    private const ALLOWED_FUNCTIONS = ['date', 'max', 'min', 'range', 'cycle'];

    private ?\Twig\Environment $twig = null;

    /** @var array<string, \Twig\TemplateWrapper> */
    private array $templates = [];

    /**
     * Renders a Twig string. Returns the input untouched when it holds no Twig
     * markers, and null for an empty one.
     */
    public function fromHtml(?string $html, array $data): ?string
    {
        if ($html === null || $html === '') return null;
        if (!preg_match('/\{[\{%#]/', $html)) return $html;

        $twig = $this->environment();

        if (strlen($html) > self::CACHE_LENGTH_LIMIT)
            return $twig->createTemplate($html)->render($data);

        $name = hash('xxh3', $html);
        $this->templates[$name] ??= $twig->createTemplate($html);

        return $this->templates[$name]->render($data);
    }

    /**
     * Renders every string of a (possibly nested) array.
     */
    public function fromModel(array $model, array $data): array
    {
        foreach ($model as $k => $v)
            if (is_string($v)) $model[$k] = $this->fromHtml($v, $data);
            elseif (is_array($v)) $model[$k] = $this->fromModel($v, $data);

        return $model;
    }

    /**
     * Renders a Twig file from a folder below DOCUMENT_ROOT.
     */
    public function fromFile(string $folder, string $file, array $data): string
    {
        $loader = new \Twig\Loader\FilesystemLoader(($_SERVER['DOCUMENT_ROOT'] ?? '') . $folder);

        return (new \Twig\Environment($loader))->render($file, $data);
    }

    /**
     * Legacy %placeholder% substitution, with optional Twig on top.
     *
     * Twig runs FIRST, on the pattern as the schema wrote it, with the values
     * as its context. The %placeholder% pass runs afterwards, so a value that
     * happens to contain '{{ ... }}' is inserted into the finished string
     * instead of becoming part of the template.
     */
    public function template(?string $pattern, array $values, bool $twig = false): string
    {
        if ($pattern === null) return '';

        if ($twig) $pattern = $this->fromHtml($pattern, $values) ?? '';

        foreach ($values as $key => $value)
            if (is_string($value)) $pattern = str_replace('%' . $key . '%', $value, $pattern);

        return $pattern ?? '';
    }

    private function environment(): \Twig\Environment
    {
        if ($this->twig !== null) return $this->twig;

        $this->twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([]));

        $policy = new \Twig\Sandbox\SecurityPolicy(
            self::ALLOWED_TAGS,
            self::ALLOWED_FILTERS,
            [],                      // no object methods
            [],                      // no object properties
            self::ALLOWED_FUNCTIONS
        );

        $this->twig->addExtension(new \Twig\Extension\SandboxExtension($policy, true));

        return $this->twig;
    }
}
