<?php

declare(strict_types=1);

namespace Ordinal\View;

use Throwable;
use SensitiveParameter;

/** Renders private PHP templates and escapes values intended for HTML text or attributes. */
final class TemplateRenderer
{
    /**
     * Renders a fixed account template; request input cannot select a file path.
     *
     * @param string $page
     * @param array $data
     * @return string
     */
    public function renderAccountPage(
        string $page,
        #[SensitiveParameter]
        array  $data,
    ): string
    {
        $templates = ['login' => 'login.php', 'account' => 'account.php', 'administration' => 'administration.php', 'projects' => 'projects.php', 'project' => 'project.php', 'devices' => 'devices.php', 'device-credential' => 'device-credential.php', 'automation' => 'automation.php', 'automation-secret' => 'automation-secret.php', 'counter' => 'counter.php', 'events' => 'events.php', 'log-cleanup' => 'log-cleanup.php'];
        if (!isset($templates[$page])) {
            throw new \InvalidArgumentException('Unknown account template.');
        }
        return $this->renderTemplate($templates[$page], $data);
    }
    /**
     * Renders the initial unstyled home page.
     *
     * @return string
     */
    public function renderHome(): string
    {
        return $this->renderTemplate('home.php', []);
    }

    /**
     * Renders a browser error using escaped message text.
     *
     * @param string $message
     * @return string
     */
    public function renderError(string $message): string
    {
        return $this->renderTemplate('error.php', ['message' => $message]);
    }

    /**
     * Escapes text using UTF-8 and quotes for safe HTML insertion.
     *
     * @param string $value
     * @return string
     */
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Captures a template selected only by this renderer, cleaning the buffer on failure.
     *
     * @param string $filename
     * @param array $data
     * @return string
     * @throws Throwable
     */
    private function renderTemplate(
        string $filename,
        #[SensitiveParameter]
        array  $data,
    ): string
    {
        ob_start();
        try {
            require dirname(__DIR__, 2) . '/widgets/page.php';
            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
    }
}
