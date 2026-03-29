<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    public function __construct(private readonly string $viewPath)
    {
    }

    public function render(string $template, array $data = [], ?string $layout = 'layouts/main'): string
    {
        $templateFile = $this->viewPath . '/' . str_replace('.', '/', $template) . '.php';
        $layoutFile = $layout ? $this->viewPath . '/' . str_replace('.', '/', $layout) . '.php' : null;

        if (!is_file($templateFile)) {
            throw new RuntimeException(sprintf('View template not found: %s', $template));
        }

        if ($layoutFile !== null && !is_file($layoutFile)) {
            throw new RuntimeException(sprintf('View layout not found: %s', $layout));
        }

        extract($data, EXTR_SKIP);

        ob_start();
        require $templateFile;
        $content = (string) ob_get_clean();

        if ($layoutFile === null) {
            return $content;
        }

        ob_start();
        require $layoutFile;

        return (string) ob_get_clean();
    }
}