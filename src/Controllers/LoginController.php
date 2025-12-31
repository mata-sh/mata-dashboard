<?php

declare(strict_types=1);

namespace Watcher\Controllers;

use Twig\Environment;
use Watcher\Core\App;
use Watcher\Utils\Tools;

/**
 * Authentication controller handling login/logout operations.
 *
 * Manages user authentication flow including:
 * - Login form display
 * - Credential validation and session creation
 * - Remember-me cookie handling
 * - Logout and session cleanup
 * - Authentication error handling
 */
class LoginController
{
    private App $app;
    private Tools $tools;
    private Environment $twig;

    /**
     * Initialize login controller with dependencies.
     */
    public function __construct(App $app, Tools $tools, Environment $twig)
    {
        $this->app = $app;
        $this->tools = $tools;
        $this->twig = $twig;
    }

    /**
     * Display login form page.
     */
    public function show(): void
    {
        $data = [
            'config' => [
                'base_url' => $this->app->getBaseUrl(),
                'full_base_url' => $this->app->getFullBaseUrl(),
            ],
            'ui' => [
                'main_logo' => $this->tools->icon('sea-turtle-bl', 150),
            ],
        ];

        echo $this->twig->render('pages/login.html.twig', $data);
    }

    /**
     * Process login form submission.
     *
     * Validates credentials and creates user session on success.
     * Redirects to dashboard or shows error message.
     */
    public function submit(): void
    {
        $username = filter_var($_POST['username'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
        $password = $_POST['password'] ?? '';
        $rememberme = filter_var($_POST['remember_me'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($this->app->auth->login($username, $password, $rememberme)) {
            $this->app->redirect('watch');

            return;
        }
        echo $this->twig->render('pages/login.html.twig', [
            'config' => [
                'base_url' => $this->app->getBaseUrl(),
                'full_base_url' => $this->app->getFullBaseUrl(),
            ],
            'ui' => [
                'main_logo' => $this->tools->icon('sea-turtle-bl', 150),
                'login_error_message' => 'Invalid login.',
            ],
        ]);
    }

    /**
     * Log out current user and display login page.
     *
     * Clears session data and remember-me cookies, then shows login form.
     */
    public function logout(): void
    {
        $this->app->auth->logout();
        echo $this->twig->render('pages/login.html.twig', [
            'config' => [
                'base_url' => $this->app->getBaseUrl(),
                'full_base_url' => $this->app->getFullBaseUrl(),
            ],
            'ui' => [
                'main_logo' => $this->tools->icon('sea-turtle-bl', 150),
            ],
        ]);
    }
}
