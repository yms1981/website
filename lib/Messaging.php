<?php

declare(strict_types=1);

/**
 * Chat interno estilo WhatsApp entre vendedores (rol 2) y clientes (rol 3).
 * Reglas: rol 3 solo con rol 2; rol 2 con rol 2 o rol 3. IDs de usuario = `users.userId` (localUserId en sesión).
 */
final class Messaging
{
    private const MAX_FILE_BYTES = 52428800; // 50 MB

    /** @var list<string> */
    private const ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'video/mp4', 'video/webm', 'video/quicktime',
        'audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/x-wav',
        'application/pdf',
    ];

    public static function storageRoot(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'messaging';
    }

    /** Usuario local para FK (tabla users). */
    public static function localUserId(array $session): int
    {
        $id = (int) ($session['localUserId'] ?? 0);

        return $id > 0 ? $id : (int) ($session['userId'] ?? 0);
    }

    public static function requireMessagingRole(array $session): void
    {
        $r = (int) ($session['rolId'] ?? 0);
        if ($r !== 2 && $r !== 3) {
            json_response(['error' => 'Forbidden'], 403);
        }
    }

    public static function pairAllowed(\PDO $pdo, int $userIdA, int $userIdB): bool
    {
        if ($userIdA <= 0 || $userIdB <= 0 || $userIdA === $userIdB) {
            return false;
        }
        $st = $pdo->prepare('SELECT `userId`, `rolId` FROM `users` WHERE `userId` IN (?, ?)');
        $st->execute([$userIdA, $userIdB]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 2) {
            return false;
        }
        $ra = $rb = 0;
        foreach ($rows as $row) {
            if ((int) ($row['userId'] ?? 0) === $userIdA) {
                $ra = (int) ($row['rolId'] ?? 0);
            } else {
                $rb = (int) ($row['rolId'] ?? 0);
            }
        }
        if ($ra === 3 && $rb === 3) {
            return false;
        }
        if ($ra === 3 && $rb !== 2) {
            return false;
        }
        if ($rb === 3 && $ra !== 2) {
            return false;
        }

        return true;
    }

    public static function userDisplayName(\PDO $pdo, int $userId): string
    {
        $st = $pdo->prepare(
            'SELECT u.`userId`, u.`username`, u.`rolId`, u.`customerId` FROM `users` u WHERE u.`userId` = ? LIMIT 1'
        );
        $st->execute([$userId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($u)) {
            return '#' . $userId;
        }
        $rol = (int) ($u['rolId'] ?? 0);
        $link = (int) ($u['customerId'] ?? 0);
        if ($rol === 2 && $link > 0) {
            try {
                require_once __DIR__ . '/FullVendorDb.php';
                $vrow = FullVendorDb::vendorNamesByUserId($link);
                if (is_array($vrow)) {
                    $full = trim((string) ($vrow['first_name'] ?? '') . ' ' . (string) ($vrow['last_name'] ?? ''));
                    if ($full !== '') {
                        return $full;
                    }
                }
            } catch (Throwable $e) {
                // fallback usersList
            }
            $sl = $pdo->prepare(
                'SELECT `first_name`, `last_name` FROM `usersList` WHERE `user_id` = ? LIMIT 1'
            );
            $sl->execute([$link]);
            $s = $sl->fetch(PDO::FETCH_ASSOC);
            if (is_array($s)) {
                $full = trim((string) ($s['first_name'] ?? '') . ' ' . (string) ($s['last_name'] ?? ''));
                if ($full !== '') {
                    return $full;
                }
            }
        }
        if ($rol === 3 && $link > 0) {
            $cs = $pdo->prepare(
                'SELECT `name`, `business_name` FROM `customers`
                 WHERE `customeridfullvendor` = ? OR `customer_id` = ? LIMIT 1'
            );
            $cs->execute([$link, $link]);
            $c = $cs->fetch(PDO::FETCH_ASSOC);
            if (is_array($c)) {
                $bn = trim((string) ($c['business_name'] ?? ''));
                $nm = trim((string) ($c['name'] ?? ''));
                if ($bn !== '') {
                    return $bn;
                }
                if ($nm !== '') {
                    return $nm;
                }
            }
        }
        $un = trim((string) ($u['username'] ?? ''));

        return $un !== '' ? $un : ('#' . $userId);
    }

    /** @return list<array{userId:int,rolId:int,display:string,username:string}> */
    public static function listContacts(\PDO $pdo, array $session): array
    {
        $me = self::localUserId($session);
        $myRol = (int) ($session['rolId'] ?? 0);
        if ($me <= 0) {
            return [];
        }
        if ($myRol === 3) {
            $st = $pdo->prepare(
                'SELECT `userId`, `username`, `rolId`, `customerId` FROM `users` WHERE `rolId` = 2 AND `userId` != ? ORDER BY `username`'
            );
            $st->execute([$me]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $st = $pdo->prepare(
                'SELECT `userId`, `username`, `rolId`, `customerId` FROM `users`
                 WHERE `userId` != ? AND `rolId` IN (2, 3) ORDER BY `rolId`, `username`'
            );
            $st->execute([$me]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uid = (int) ($row['userId'] ?? 0);
            if ($uid === $me || !self::pairAllowed($pdo, $me, $uid)) {
                continue;
            }
            $out[] = [
                'userId' => $uid,
                'rolId' => (int) ($row['rolId'] ?? 0),
                'display' => self::userDisplayName($pdo, $uid),
                'username' => (string) ($row['username'] ?? ''),
            ];
        }

        return $out;
    }

    public static function getOrCreateConversation(\PDO $pdo, int $userA, int $userB): int
    {
        if ($userA === $userB) {
            return 0;
        }
        if (!self::pairAllowed($pdo, $userA, $userB)) {
            return 0;
        }
        $low = min($userA, $userB);
        $high = max($userA, $userB);
        $st = $pdo->prepare(
            'SELECT `id` FROM `messaging_conversation` WHERE `user_low` = ? AND `user_high` = ? LIMIT 1'
        );
        $st->execute([$low, $high]);
        $id = $st->fetchColumn();
        if ($id !== false && (int) $id > 0) {
            return (int) $id;
        }
        $ins = $pdo->prepare(
            'INSERT INTO `messaging_conversation` (`user_low`, `user_high`) VALUES (?, ?)'
        );
        $ins->execute([$low, $high]);

        return (int) $pdo->lastInsertId();
    }

    public static function otherUserId(int $low, int $high, int $me): int
    {
        return $me === $low ? $high : $low;
    }

    /** @return list<array<string,mixed>> */
    public static function listConversations(\PDO $pdo, array $session): array
    {
        $me = self::localUserId($session);
        if ($me <= 0) {
            return [];
        }
        $sql = <<<'SQL'
SELECT c.`id`, c.`user_low`, c.`user_high`, c.`last_message_at`,
  (SELECT m.`body` FROM `messaging_message` m WHERE m.`conversation_id` = c.`id` ORDER BY m.`id` DESC LIMIT 1) AS `last_body`,
  (SELECT m.`msg_type` FROM `messaging_message` m WHERE m.`conversation_id` = c.`id` ORDER BY m.`id` DESC LIMIT 1) AS `last_type`,
  (SELECT m.`sender_user_id` FROM `messaging_message` m WHERE m.`conversation_id` = c.`id` ORDER BY m.`id` DESC LIMIT 1) AS `last_sender_id`,
  (SELECT m.`id` FROM `messaging_message` m WHERE m.`conversation_id` = c.`id` ORDER BY m.`id` DESC LIMIT 1) AS `last_message_id`
FROM `messaging_conversation` c
WHERE c.`user_low` = ? OR c.`user_high` = ?
ORDER BY COALESCE(c.`last_message_at`, c.`created_at`) DESC
SQL;
        $st = $pdo->prepare($sql);
        $st->execute([$me, $me]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $otherIds = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $low = (int) ($r['user_low'] ?? 0);
            $high = (int) ($r['user_high'] ?? 0);
            $otherIds[self::otherUserId($low, $high, $me)] = true;
        }
        $rolMap = [];
        $idList = array_keys($otherIds);
        $userMeta = [];
        if ($idList !== []) {
            $placeholders = implode(',', array_fill(0, count($idList), '?'));
            $stR = $pdo->prepare('SELECT `userId`, `rolId`, `username` FROM `users` WHERE `userId` IN (' . $placeholders . ')');
            $stR->execute($idList);
            while ($rr = $stR->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($rr)) {
                    $uid = (int) ($rr['userId'] ?? 0);
                    $rolMap[$uid] = (int) ($rr['rolId'] ?? 0);
                    $userMeta[$uid] = (string) ($rr['username'] ?? '');
                }
            }
        }
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $cid = (int) ($r['id'] ?? 0);
            $low = (int) ($r['user_low'] ?? 0);
            $high = (int) ($r['user_high'] ?? 0);
            $other = self::otherUserId($low, $high, $me);
            if ($other === $me) {
                continue;
            }
            $lastMid = (int) ($r['last_message_id'] ?? 0);
            $unread = self::unreadCount($pdo, $cid, $me, $lastMid);
            $preview = self::previewText((string) ($r['last_type'] ?? 'text'), (string) ($r['last_body'] ?? ''));
            $out[] = [
                'conversationId' => $cid,
                'otherUserId' => $other,
                'otherRolId' => $rolMap[$other] ?? 0,
                'otherName' => self::userDisplayName($pdo, $other),
                'otherUsername' => $userMeta[$other] ?? '',
                'lastPreview' => $preview,
                'lastMessageAt' => $r['last_message_at'] ?? null,
                'unread' => $unread,
            ];
        }

        return $out;
    }

    private static function previewText(string $type, string $body): string
    {
        $t = trim($body);
        if ($t !== '') {
            return mb_strlen($t) > 80 ? mb_substr($t, 0, 77) . '…' : $t;
        }

        return match ($type) {
            'image' => '📷',
            'video' => '🎬',
            'audio' => '🎤',
            'file' => '📎',
            default => '',
        };
    }

    public static function unreadCount(\PDO $pdo, int $conversationId, int $readerId, int $lastMessageIdInConv): int
    {
        if ($conversationId <= 0 || $lastMessageIdInConv <= 0) {
            return 0;
        }
        $st = $pdo->prepare(
            'SELECT `last_read_message_id` FROM `messaging_read`
             WHERE `conversation_id` = ? AND `user_id` = ? LIMIT 1'
        );
        $st->execute([$conversationId, $readerId]);
        $lr = (int) ($st->fetchColumn() ?: 0);
        $cntSt = $pdo->prepare(
            'SELECT COUNT(*) FROM `messaging_message`
             WHERE `conversation_id` = ? AND `sender_user_id` != ? AND `id` > ?'
        );
        $cntSt->execute([$conversationId, $readerId, $lr]);

        return (int) $cntSt->fetchColumn();
    }

    public static function totalUnreadBadge(\PDO $pdo, array $session): int
    {
        $me = self::localUserId($session);
        if ($me <= 0) {
            return 0;
        }
        $list = self::listConversations($pdo, $session);
        $n = 0;
        foreach ($list as $c) {
            $n += (int) ($c['unread'] ?? 0);
        }

        return $n;
    }

    public static function assertParticipant(\PDO $pdo, int $conversationId, int $userId): ?array
    {
        $st = $pdo->prepare(
            'SELECT `id`, `user_low`, `user_high` FROM `messaging_conversation` WHERE `id` = ? LIMIT 1'
        );
        $st->execute([$conversationId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $low = (int) ($row['user_low'] ?? 0);
        $high = (int) ($row['user_high'] ?? 0);
        if ($userId !== $low && $userId !== $high) {
            return null;
        }

        return ['id' => (int) $row['id'], 'user_low' => $low, 'user_high' => $high];
    }

    /** Último mensaje que el otro participante marcó como leído (para dobles checks en UI). */
    public static function peerLastReadMessageId(\PDO $pdo, int $conversationId, int $viewerId): int
    {
        $conv = self::assertParticipant($pdo, $conversationId, $viewerId);
        if ($conv === null) {
            return 0;
        }
        $other = self::otherUserId($conv['user_low'], $conv['user_high'], $viewerId);
        $st = $pdo->prepare(
            'SELECT `last_read_message_id` FROM `messaging_read` WHERE `conversation_id` = ? AND `user_id` = ? LIMIT 1'
        );
        $st->execute([$conversationId, $other]);

        return (int) ($st->fetchColumn() ?: 0);
    }

    /** @return list<array<string,mixed>> */
    public static function listMessages(\PDO $pdo, int $conversationId, int $readerId, int $afterId = 0, int $limit = 80): array
    {
        $conv = self::assertParticipant($pdo, $conversationId, $readerId);
        if ($conv === null) {
            return [];
        }
        $low = (int) ($conv['user_low'] ?? 0);
        $high = (int) ($conv['user_high'] ?? 0);
        if ($low === $high) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $st = $pdo->prepare(
            'SELECT `id`, `sender_user_id`, `body`, `msg_type`, `file_name`, `mime_type`, `file_size`, `public_token`, `created_at`
             FROM `messaging_message`
             WHERE `conversation_id` = ? AND `id` > ?
             ORDER BY `id` ASC
             LIMIT ' . (int) $limit
        );
        $st->execute([$conversationId, $afterId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $out[] = [
                'id' => (int) ($r['id'] ?? 0),
                'senderUserId' => (int) ($r['sender_user_id'] ?? 0),
                'mine' => (int) ($r['sender_user_id'] ?? 0) === $readerId,
                'body' => (string) ($r['body'] ?? ''),
                'type' => (string) ($r['msg_type'] ?? 'text'),
                'fileName' => (string) ($r['file_name'] ?? ''),
                'mimeType' => (string) ($r['mime_type'] ?? ''),
                'fileSize' => (int) ($r['file_size'] ?? 0),
                'fileUrl' => self::fileUrl((string) ($r['public_token'] ?? '')),
                'createdAt' => (string) ($r['created_at'] ?? ''),
            ];
        }

        return $out;
    }

    public static function fileUrl(string $token): string
    {
        if ($token === '' || strlen($token) !== 32) {
            return '';
        }
        $lang = (string) ($GLOBALS['hv_lang'] ?? 'en');
        if ($lang !== 'es') {
            $lang = 'en';
        }

        return base_url() . '/' . $lang . '/account/messages/file?t=' . rawurlencode($token);
    }

    public static function markRead(\PDO $pdo, int $conversationId, int $readerId, int $messageId): void
    {
        if ($messageId <= 0 || self::assertParticipant($pdo, $conversationId, $readerId) === null) {
            return;
        }
        $pdo->prepare(
            'INSERT INTO `messaging_read` (`conversation_id`, `user_id`, `last_read_message_id`)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `last_read_message_id` = GREATEST(`last_read_message_id`, VALUES(`last_read_message_id`)), `updated_at` = CURRENT_TIMESTAMP(3)'
        )->execute([$conversationId, $readerId, $messageId]);
    }

    /**
     * @return array{ok:bool,error?:string,message?:array<string,mixed>}
     */
    public static function sendText(\PDO $pdo, array $session, int $conversationId, int $otherUserId, string $body): array
    {
        $me = self::localUserId($session);
        if ($me <= 0) {
            return ['ok' => false, 'error' => 'Invalid session'];
        }
        $body = trim($body);
        if ($body === '') {
            return ['ok' => false, 'error' => 'Empty message'];
        }
        if (mb_strlen($body) > 8000) {
            return ['ok' => false, 'error' => 'Message too long'];
        }
        if ($otherUserId > 0 && $otherUserId === $me) {
            return ['ok' => false, 'error' => 'Cannot message yourself'];
        }
        if ($conversationId <= 0 && $otherUserId > 0) {
            $conversationId = self::getOrCreateConversation($pdo, $me, $otherUserId);
        }
        if ($conversationId <= 0 || self::assertParticipant($pdo, $conversationId, $me) === null) {
            return ['ok' => false, 'error' => 'Invalid conversation'];
        }
        $conv = self::assertParticipant($pdo, $conversationId, $me);
        if ($conv === null) {
            return ['ok' => false, 'error' => 'Invalid conversation'];
        }
        $other = self::otherUserId($conv['user_low'], $conv['user_high'], $me);
        if ($other === $me) {
            return ['ok' => false, 'error' => 'Cannot message yourself'];
        }
        if (!self::pairAllowed($pdo, $me, $other)) {
            return ['ok' => false, 'error' => 'Not allowed'];
        }
        $token = bin2hex(random_bytes(16));
        $ins = $pdo->prepare(
            'INSERT INTO `messaging_message`
            (`conversation_id`, `sender_user_id`, `body`, `msg_type`, `public_token`)
             VALUES (?, ?, ?, \'text\', ?)'
        );
        $ins->execute([$conversationId, $me, $body, $token]);
        $mid = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'UPDATE `messaging_conversation` SET `last_message_at` = CURRENT_TIMESTAMP(3) WHERE `id` = ?'
        )->execute([$conversationId]);

        return ['ok' => true, 'message' => self::messageRowById($pdo, $mid, $me)];
    }

    /** @return ?array<string,mixed> */
    private static function messageRowById(\PDO $pdo, int $messageId, int $readerId): ?array
    {
        $st = $pdo->prepare(
            'SELECT `id`, `conversation_id`, `sender_user_id`, `body`, `msg_type`, `file_rel_path`, `file_name`, `mime_type`, `file_size`, `public_token`, `created_at`
             FROM `messaging_message` WHERE `id` = ? LIMIT 1'
        );
        $st->execute([$messageId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($r)) {
            return null;
        }
        $hasFile = (string) ($r['file_rel_path'] ?? '') !== '';

        return [
            'id' => (int) ($r['id'] ?? 0),
            'conversationId' => (int) ($r['conversation_id'] ?? 0),
            'senderUserId' => (int) ($r['sender_user_id'] ?? 0),
            'mine' => (int) ($r['sender_user_id'] ?? 0) === $readerId,
            'body' => (string) ($r['body'] ?? ''),
            'type' => (string) ($r['msg_type'] ?? 'text'),
            'fileName' => (string) ($r['file_name'] ?? ''),
            'mimeType' => (string) ($r['mime_type'] ?? ''),
            'fileSize' => (int) ($r['file_size'] ?? 0),
            'fileUrl' => $hasFile ? self::fileUrl((string) ($r['public_token'] ?? '')) : '',
            'createdAt' => (string) ($r['created_at'] ?? ''),
        ];
    }

    public static function mimeToType(string $mime): string
    {
        $m = strtolower(trim($mime));
        if (str_starts_with($m, 'image/')) {
            return 'image';
        }
        if (str_starts_with($m, 'video/')) {
            return 'video';
        }
        if (str_starts_with($m, 'audio/')) {
            return 'audio';
        }

        return 'file';
    }

    public static function allowedMime(string $mime): bool
    {
        return in_array(strtolower(trim($mime)), self::ALLOWED_MIMES, true);
    }

    /** Maneja POST multipart desde la API (termina con json_response). */
    public static function handleMultipartUpload(\PDO $pdo, array $session): void
    {
        $me = self::localUserId($session);
        if ($me <= 0) {
            json_response(['error' => 'Invalid session'], 400);
        }
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            json_response(['error' => 'No file'], 400);
        }
        $f = $_FILES['file'];
        if (!empty($f['error']) && (int) $f['error'] !== UPLOAD_ERR_OK) {
            json_response(['error' => 'Upload failed'], 400);
        }
        $convId = (int) ($_POST['conversationId'] ?? 0);
        $otherId = (int) ($_POST['otherUserId'] ?? 0);
        $caption = trim((string) ($_POST['caption'] ?? ''));
        if ($caption !== '' && mb_strlen($caption) > 8000) {
            json_response(['error' => 'Caption too long'], 400);
        }
        if ($otherId > 0 && $otherId === $me) {
            json_response(['error' => 'Cannot message yourself'], 400);
        }
        if ($convId <= 0 && $otherId > 0) {
            $convId = self::getOrCreateConversation($pdo, $me, $otherId);
        }
        if ($convId <= 0 || self::assertParticipant($pdo, $convId, $me) === null) {
            json_response(['error' => 'Invalid conversation'], 400);
        }
        $conv = self::assertParticipant($pdo, $convId, $me);
        if ($conv === null) {
            json_response(['error' => 'Invalid conversation'], 400);
        }
        $other = self::otherUserId($conv['user_low'], $conv['user_high'], $me);
        if ($other === $me) {
            json_response(['error' => 'Cannot message yourself'], 400);
        }
        if (!self::pairAllowed($pdo, $me, $other)) {
            json_response(['error' => 'Not allowed'], 403);
        }
        $tmp = (string) ($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            json_response(['error' => 'Invalid upload'], 400);
        }
        $size = (int) ($f['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_FILE_BYTES) {
            json_response(['error' => 'File too large'], 400);
        }
        $mime = (string) (mime_content_type($tmp) ?: '');
        if ($mime === '' || !self::allowedMime($mime)) {
            json_response(['error' => 'File type not allowed'], 400);
        }
        $origName = basename((string) ($f['name'] ?? 'file'));
        if (strlen($origName) > 200) {
            $origName = mb_substr($origName, 0, 200);
        }
        $msgType = self::mimeToType($mime);
        $ext = pathinfo($origName, PATHINFO_EXTENSION);
        $ext = is_string($ext) && preg_match('/^[a-zA-Z0-9]{1,8}$/', $ext) ? strtolower($ext) : 'bin';
        $token = bin2hex(random_bytes(16));
        $ins = $pdo->prepare(
            'INSERT INTO `messaging_message`
            (`conversation_id`, `sender_user_id`, `body`, `msg_type`, `file_name`, `mime_type`, `file_size`, `public_token`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $convId,
            $me,
            $caption !== '' ? $caption : null,
            $msgType,
            $origName,
            $mime,
            $size,
            $token,
        ]);
        $mid = (int) $pdo->lastInsertId();
        $rel = $convId . DIRECTORY_SEPARATOR . $mid . '_' . substr($token, 0, 8) . '.' . $ext;
        $root = self::storageRoot();
        $dir = $root . DIRECTORY_SEPARATOR . $convId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $pdo->prepare('DELETE FROM `messaging_message` WHERE `id` = ?')->execute([$mid]);
            json_response(['error' => 'Storage error'], 500);
        }
        $dest = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!move_uploaded_file($tmp, $dest)) {
            $pdo->prepare('DELETE FROM `messaging_message` WHERE `id` = ?')->execute([$mid]);
            json_response(['error' => 'Could not save file'], 500);
        }
        $pdo->prepare(
            'UPDATE `messaging_message` SET `file_rel_path` = ? WHERE `id` = ?'
        )->execute([str_replace('\\', '/', $rel), $mid]);
        $pdo->prepare(
            'UPDATE `messaging_conversation` SET `last_message_at` = CURRENT_TIMESTAMP(3) WHERE `id` = ?'
        )->execute([$convId]);
        $row = self::messageRowById($pdo, $mid, $me);
        json_response(['ok' => true, 'message' => $row]);
    }

    /** Sirve archivo si el token es válido y el usuario pertenece a la conversación. */
    public static function streamAttachment(\PDO $pdo, array $session, string $token): void
    {
        $token = strtolower(preg_replace('/[^a-f0-9]/', '', $token) ?? '');
        if (strlen($token) !== 32) {
            http_response_code(404);
            exit;
        }
        $me = self::localUserId($session);
        if ($me <= 0) {
            http_response_code(403);
            exit;
        }
        $st = $pdo->prepare(
            'SELECT m.`id`, m.`conversation_id`, m.`file_rel_path`, m.`mime_type`, m.`file_name`
             FROM `messaging_message` m WHERE m.`public_token` = ? LIMIT 1'
        );
        $st->execute([$token]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($r) || empty($r['file_rel_path'])) {
            http_response_code(404);
            exit;
        }
        $cid = (int) ($r['conversation_id'] ?? 0);
        if (self::assertParticipant($pdo, $cid, $me) === null) {
            http_response_code(403);
            exit;
        }
        $rel = str_replace(['..', "\0"], '', (string) $r['file_rel_path']);
        $path = self::storageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $real = realpath($path);
        $rootReal = realpath(self::storageRoot());
        if ($real === false || $rootReal === false || !str_starts_with($real, $rootReal) || !is_file($real)) {
            http_response_code(404);
            exit;
        }
        $mime = (string) ($r['mime_type'] ?? 'application/octet-stream');
        $fname = basename((string) ($r['file_name'] ?? 'file'));
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) filesize($real));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $fname) . '"');
        readfile($real);
        exit;
    }
}
