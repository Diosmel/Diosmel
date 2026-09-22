<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

if (!is_installed()) {
    redirect('setup.php');
}

if (admin_logged_in()) {
    redirect('admin.php');
}

$errors = [];
$username = '';

if (is_post()) {
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $result = attempt_admin_login($username, (string) ($_POST['password'] ?? ''));

    if ($result['success']) {
        set_flash('success', 'Sesión iniciada correctamente.');
        redirect('admin.php');
    }

    $errors[] = (string) $result['message'];
}

render_page_start('Acceso privado', 'auth-page');
?>
<main class="centered-page">
    <section class="auth-card">
        <a class="auth-brand" href="index.php">
            <span class="brand-mark" aria-hidden="true">ME</span>
            <span><?= h((string) app_config('app_name')) ?></span>
        </a>
        <span class="eyebrow">Administración</span>
        <h1>Acceso privado</h1>
        <p>Solo la cuenta administradora puede modificar el registro.</p>

        <?php render_flash_message(); ?>
        <?php render_errors($errors); ?>

        <form method="post" action="login.php" class="stacked-form">
            <?= csrf_input() ?>
            <label class="field">
                <span class="field-label">Usuario</span>
                <input type="text" name="username" value="<?= h($username) ?>" autocomplete="username" required autofocus>
            </label>
            <div class="field">
                <label class="field-label" for="login-password">Contraseña</label>
                <span class="password-control">
                    <input id="login-password" type="password" name="password" autocomplete="current-password" required>
                    <button type="button" data-toggle-password="login-password" aria-label="Mostrar contraseña">Ver</button>
                </span>
            </div>
            <button class="button button-primary button-wide" type="submit">Entrar al panel</button>
        </form>

        <a class="back-link" href="index.php">Volver al registro público</a>
    </section>
</main>
</body>
</html>
