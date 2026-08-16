<?php

declare(strict_types=1);

namespace Mata\Controllers;

use Mata\Core\App;
use Mata\Http\Security\CsrfGuard;
use Mata\Utils\Tools;
use Twig\Environment;

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
    private CsrfGuard $csrf;
    private Environment $twig;

    /**
     * Initialize login controller with dependencies.
     */
    public function __construct(App $app, Tools $tools, Environment $twig, CsrfGuard $csrf)
    {
        $this->app = $app;
        $this->tools = $tools;
        $this->twig = $twig;
        $this->csrf = $csrf;
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
        $rawUsername = $_POST['username'] ?? '';
        $rawPassword = $_POST['password'] ?? '';
        $rawRememberMe = $_POST['remember_me'] ?? false;

        $username = is_string($rawUsername) ? trim($rawUsername) : '';
        $password = is_string($rawPassword) ? $rawPassword : '';
        $rememberme = in_array($rawRememberMe, [true, 1, '1', 'true', 'on'], true);

        if ($this->app->auth->login($username, $password, $rememberme)) {
            $this->csrf->regenerate();
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
     * Clear authentication state and redirect to a fresh login session.
     */
    public function logout(): void
    {
        $this->app->auth->logout();
        $this->app->redirect('login');
    }
}
