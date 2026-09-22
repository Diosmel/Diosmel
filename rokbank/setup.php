<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

if (is_installed()) {
    redirect(admin_logged_in() ? 'admin.php' : 'login.php');
}

$errors = [];
$username = 'Diosmel';

if (is_post()) {
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));

    try {
        $errors = create_admin_account(
            (string) ($_POST['setup_key'] ?? ''),
            $username,
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['password_confirmation'] ?? '')
        );

        if ($errors === []) {
            set_flash('success', 'Acceso administrativo creado. Ya puedes iniciar sesión.');
            redirect('login.php');
        }
    } catch (Throwable $exception) {
        $errors[] = 'No se pudo completar la instalación. Comprueba los permisos de la carpeta storage.';
    }
}

render_page_start('Configuración inicial', 'auth-page');
?>
<main class="centered-page">
    <section class="auth-card setup-card">
        <a class="auth-brand" href="index.php">
            <span class="brand-mark" aria-hidden="true">ME</span>
            <span><?= h((string) app_config('app_name')) ?></span>
        </a>
        <span class="eyebrow">Configuración única</span>
        <h1>Crea tu acceso privado</h1>
        <p>Este usuario será el único autorizado para añadir, editar o eliminar aportes.</p>

        <?php if (!app_is_https()): ?>
            <div class="form-warning" role="note">
                Abre esta página usando <strong>https://</strong> antes de crear la contraseña.
            </div>
        <?php endif; ?>

        <?php render_errors($errors); ?>

        <form method="post" action="setup.php" class="stacked-form" novalidate>
            <?= csrf_input() ?>
            <label class="field">
                <span class="field-label">Código de instalación</span>
                <input type="password" name="setup_key" autocomplete="off" required autofocus>
                <small>Está en el archivo LEEME_PRIMERO.txt incluido en el ZIP.</small>
            </label>
            <label class="field">
                <span class="field-label">Usuario administrador</span>
                <input type="text" name="username" value="<?= h($username) ?>" autocomplete="username" minlength="3" maxlength="40" required>
            </label>
            <div class="field">
                <label class="field-label" for="setup-password">Contraseña</label>
                <span class="password-control">
                    <input id="setup-password" type="password" name="password" autocomplete="new-password" minlength="<?= h((string) app_config('minimum_password_length')) ?>" required>
                    <button type="button" data-toggle-password="setup-password" aria-label="Mostrar contraseña">Ver</button>
                </span>
                <small>Mínimo <?= h((string) app_config('minimum_password_length')) ?> caracteres.</small>
            </div>
            <div class="field">
                <label class="field-label" for="setup-confirmation">Repite la contraseña</label>
                <span class="password-control">
                    <input id="setup-confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="<?= h((string) app_config('minimum_password_length')) ?>" required>
                    <button type="button" data-toggle-password="setup-confirmation" aria-label="Mostrar contraseña repetida">Ver</button>
                </span>
            </div>
            <button class="button button-primary button-wide" type="submit">Crear acceso administrativo</button>
        </form>

        <p class="auth-footnote">La contraseña queda cifrada. No existe recuperación por correo, así que guárdala en un lugar seguro.</p>
    </section>
</main>
</body>
</html>
