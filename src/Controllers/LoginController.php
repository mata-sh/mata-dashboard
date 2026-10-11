<?php

declare(strict_types=1);

namespace Mata\Controllers;

use Mata\Core\App;
use Mata\Http\Request;
use Mata\Http\Security\CsrfGuard;
use Mata\Utils\Tools;
use RuntimeException;
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
    private readonly Request $request;

    /**
     * Initialize login controller with dependencies.
     */
    public function __construct(App $app, Tools $tools, Environment $twig, CsrfGuard $csrf, Request $request)
    {
        $this->app = $app;
        $this->tools = $tools;
        $this->twig = $twig;
        $this->csrf = $csrf;
        $this->request = $request;
    }

    /**
     * Display login form page.
     */
    public function show(): void
    {
        $data = [
            'config' => [
                'base_host' => $this->app->getBaseHost(),
                'full_base_url' => $this->app->getFullBaseUrl(),
            ],
            'ui' => [
                'main_logo' => $this->tools->icon('sea-turtle-bl', 150),
            ],
            'demo_login' => $this->demoLogin(),
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
        $username = trim($this->request->postString('username') ?? '');
        $password = $this->request->postString('password') ?? '';
        $rememberme = $this->request->postBool('remember_me');

        try {
            if ($this->app->auth->login($username, $password, $rememberme)) {
                $this->csrf->regenerate();
                $this->app->redirect('watch');

                return;
            }
        } catch (RuntimeException) {
            // The users file could not be locked or written; Auth has logged why.
            $this->renderLogin('Sign-in is temporarily unavailable. Please try again.');

            return;
        }

        $this->renderLogin('Invalid login.');
    }

    private function renderLogin(string $errorMessage): void
    {
        echo $this->twig->render('pages/login.html.twig', [
            'config' => [
                'base_host' => $this->app->getBaseHost(),
                'full_base_url' => $this->app->getFullBaseUrl(),
            ],
            'ui' => [
                'main_logo' => $this->tools->icon('sea-turtle-bl', 150),
                'login_error_message' => $errorMessage,
            ],
            'demo_login' => $this->demoLogin(),
        ]);
    }

    /**
     * Public demo credentials to show and prefill, only on a demo instance with both configured.
     *
     * @return null|array{username: string, password: string}
     */
    private function demoLogin(): ?array
    {
        $demo = $this->app->config->demo;
        if ($this->app->config->environment->env !== 'demo'
            || $demo->loginUsername === null
            || $demo->loginPassword === null) {
            return null;
        }

        return ['username' => $demo->loginUsername, 'password' => $demo->loginPassword];
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
