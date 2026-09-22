<?php
declare(strict_types=1);

/**
 * Acciones administrativas de los comunicados y de sus archivos.
 *
 * Este archivo se incluye desde admin.php dentro del bloque POST, después de
 * haber verificado la sesión administrativa y el token CSRF. Trabaja sobre las
 * variables $errors, $announcementForm, $announcementPreview y $view.
 */

if (!defined('ROKBANK_ADMIN_CONTEXT')) {
    // Evita que el archivo se ejecute por su cuenta aunque el servidor web lo
    // sirviera por error: includes/ ya está bloqueado por .htaccess.
    http_response_code(403);
    exit;
}

if (!announcements_available()) {
    $errors[] = 'Los comunicados necesitan el almacenamiento MySQL del banco.';
    return;
}

$announcementId = (int) ($_POST['announcement_id'] ?? 0);

if ($action === 'announcement_save' || $action === 'announcement_preview') {
    $input = announcement_input($_POST);
    $errors = $input['errors'];
    $announcementForm = array_merge($input['values'], [
        'id' => $announcementId,
        'status' => (string) ($_POST['current_status'] ?? 'draft'),
    ]);
    $announcementEditId = $announcementId;
    if ($action === 'announcement_preview') {
        // La vista previa usa exactamente el mismo generador de HTML que la
        // página pública, por eso no puede diferir del resultado publicado.
        $announcementPreview = $announcementForm;
        return;
    }
    if ($errors === []) {
        $result = announcement_save($announcementForm, $announcementId, (string) ($_POST['change_note'] ?? ''));
        $errors = $result['errors'];
        if ($errors === []) {
            set_flash('success', 'Comunicado guardado como borrador. Revisa la vista previa antes de publicarlo.');
            redirect('admin.php?view=announcements&announcement_id=' . (int) $result['id']);
        }
    }
    return;
}

if ($action === 'announcement_publish' || $action === 'announcement_unpublish' || $action === 'announcement_archive') {
    $status = $action === 'announcement_publish' ? 'published' : ($action === 'announcement_archive' ? 'archived' : 'draft');
    $errors = announcement_set_status($announcementId, $status);
    if ($errors === []) {
        $messages = [
            'published' => 'Comunicado publicado. Ya es visible en la sección pública.',
            'draft' => 'Publicación retirada. El comunicado vuelve a ser un borrador privado.',
            'archived' => 'Comunicado archivado.',
        ];
        set_flash('success', $messages[$status]);
        redirect('admin.php?view=announcements&announcement_id=' . $announcementId);
    }
    return;
}

if ($action === 'announcement_pin' || $action === 'announcement_unpin') {
    $errors = announcement_set_pinned($announcementId, $action === 'announcement_pin');
    if ($errors === []) {
        set_flash('success', $action === 'announcement_pin'
            ? 'Comunicado fijado en la portada.' : 'Comunicado retirado de la portada.');
        redirect('admin.php?view=announcements&announcement_id=' . $announcementId);
    }
    return;
}

if ($action === 'announcement_duplicate') {
    $result = announcement_duplicate($announcementId);
    $errors = $result['errors'];
    if ($errors === []) {
        set_flash('success', 'Comunicado duplicado como borrador nuevo.');
        redirect('admin.php?view=announcements&announcement_id=' . (int) $result['id']);
    }
    return;
}

if ($action === 'announcement_restore_revision') {
    $result = announcement_restore_revision((int) ($_POST['revision_id'] ?? 0));
    $errors = $result['errors'];
    if ($errors === []) {
        set_flash('success', 'Revisión restaurada como borrador nuevo. El comunicado original no cambió.');
        redirect('admin.php?view=announcements&announcement_id=' . (int) $result['id']);
    }
    return;
}

if ($action === 'announcement_delete') {
    $errors = announcement_delete($announcementId);
    if ($errors === []) {
        set_flash('success', 'Comunicado eliminado. Los archivos compartidos con otros comunicados se conservaron.');
        redirect('admin.php?view=announcements');
    }
    return;
}

if ($action === 'media_upload') {
    if (!isset($_FILES['media_files'])) {
        $errors[] = 'No se recibió ningún archivo. Puede que superara el límite de post_max_size del servidor.';
        return;
    }
    $result = media_store_uploads(
        (array) $_FILES['media_files'],
        (string) ($_POST['alt_text'] ?? ''),
        (string) ($_POST['caption'] ?? '')
    );
    $errors = $result['errors'];
    if ($result['ids'] !== []) {
        set_flash($errors === [] ? 'success' : 'info', count($result['ids']) . ' archivo(s) cargado(s) correctamente.'
            . ($errors === [] ? '' : ' Algunos se rechazaron: ' . implode(' ', $errors)));
        redirect('admin.php?view=announcements' . ($announcementId > 0 ? '&announcement_id=' . $announcementId : ''));
    }
    return;
}

if ($action === 'media_update') {
    $errors = media_update_description(
        (int) ($_POST['media_id'] ?? 0),
        (string) ($_POST['alt_text'] ?? ''),
        (string) ($_POST['caption'] ?? '')
    );
    if ($errors === []) {
        set_flash('success', 'Descripción del archivo actualizada.');
        redirect('admin.php?view=announcements');
    }
    return;
}

if ($action === 'media_delete') {
    $errors = media_delete((int) ($_POST['media_id'] ?? 0));
    if ($errors === []) {
        set_flash('success', 'Archivo eliminado de la biblioteca.');
        redirect('admin.php?view=announcements');
    }
    return;
}

$errors[] = 'La acción solicitada no es válida.';
