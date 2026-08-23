<?php
/**
 * API message localization (rw / en / fr).
 * Language from X-Gugu-Lang header, ?lang=, or JSON body lang.
 */

function requestLang(): string {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $candidates = [
        trim((string) ($_SERVER['HTTP_X_GUGU_LANG'] ?? '')),
        trim((string) ($_GET['lang'] ?? '')),
    ];
    // Body lang only when already parsed elsewhere is expensive; header is primary.
    foreach ($candidates as $c) {
        $c = strtolower($c);
        if ($c === 'en' || $c === 'fr' || $c === 'rw') {
            $cached = $c;
            return $cached;
        }
    }
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if (str_starts_with($accept, 'en')) {
        $cached = 'en';
    } elseif (str_starts_with($accept, 'fr')) {
        $cached = 'fr';
    } else {
        $cached = 'rw';
    }
    return $cached;
}

/** @return array<string, array{rw:string,en:string,fr:string}> */
function apiMessageCatalog(): array {
    return [
        'phone_invalid' => [
            'rw' => 'Nomero ya telefoni ntabwo ari yo',
            'en' => 'Phone number is not valid',
            'fr' => 'Le numéro de téléphone est invalide',
        ],
        'phone_invalid_format' => [
            'rw' => 'Nomero ya telefoni ntabwo ari yo (+2507XXXXXXXX)',
            'en' => 'Use a Rwanda phone like 078XXXXXXX',
            'fr' => 'Utilisez un numéro rwandais (078XXXXXXX)',
        ],
        'otp_invalid' => [
            'rw' => 'OTP ntabwo ari yo',
            'en' => 'OTP code is incorrect',
            'fr' => 'Le code OTP est incorrect',
        ],
        'otp_expired' => [
            'rw' => 'OTP yarangiye — saba indi',
            'en' => 'OTP expired — request a new one',
            'fr' => 'OTP expiré — demandez-en un nouveau',
        ],
        'password_or_otp' => [
            'rw' => "Andika ijambo ry'ibanga cyangwa OTP",
            'en' => 'Enter your password or use OTP',
            'fr' => 'Entrez le mot de passe ou utilisez l’OTP',
        ],
        'login_failed' => [
            'rw' => "Nomero cyangwa ijambo ry'ibanga ntabwo ari byo",
            'en' => 'Phone or password is incorrect',
            'fr' => 'Téléphone ou mot de passe incorrect',
        ],
        'account_suspended' => [
            'rw' => 'Konti yawe yahagaritswe',
            'en' => 'Your account is suspended',
            'fr' => 'Votre compte est suspendu',
        ],
        'password_short' => [
            'rw' => "Ijambo ry'ibanga rigomba kuba nibura inyuguti 6",
            'en' => 'Password must be at least 6 characters',
            'fr' => 'Le mot de passe doit contenir au moins 6 caractères',
        ],
        'fill_all' => [
            'rw' => 'Uzuza amakuru yose',
            'en' => 'Please fill in all required fields',
            'fr' => 'Veuillez remplir tous les champs obligatoires',
        ],
        'fill_nickname_district' => [
            'rw' => "Uzuza nickname, intara n'akarere",
            'en' => 'Enter nickname, province and district',
            'fr' => 'Entrez le pseudo, la province et le district',
        ],
        'phone_taken' => [
            'rw' => 'Iyi nomero ya telefoni isanzwe ikoreshwa',
            'en' => 'This phone number is already registered',
            'fr' => 'Ce numéro est déjà enregistré',
        ],
        'id_number_required' => [
            'rw' => "Andika numero y'indangamuntu",
            'en' => 'Enter your national ID number',
            'fr' => 'Entrez votre numéro de pièce',
        ],
        'id_photo_required' => [
            'rw' => "Shyiramo ifoto y'indangamuntu",
            'en' => 'Upload a photo of your ID',
            'fr' => 'Envoyez une photo de votre pièce',
        ],
        'id_photo_invalid' => [
            'rw' => 'Ifoto ntabwo yemewe',
            'en' => 'That photo is not accepted',
            'fr' => 'Cette photo n’est pas acceptée',
        ],
        'location_rwanda' => [
            'rw' => 'Location must be in Rwanda',
            'en' => 'Location must be in Rwanda',
            'fr' => 'La position doit être au Rwanda',
        ],
        'staff_portal_only' => [
            'rw' => 'Staff portal for management accounts only',
            'en' => 'Staff portal for management accounts only',
            'fr' => 'Portail réservé aux comptes management',
        ],
        'login_required' => [
            'rw' => 'Nyamuneka winjire mbere (Please login first)',
            'en' => 'Please log in first',
            'fr' => 'Veuillez vous connecter d’abord',
        ],
        'account_banned' => [
            'rw' => 'Konti yawe yahagaritswe. Vugana na Gura & Gurisha support.',
            'en' => 'Your account is banned. Contact Gura & Gurisha support.',
            'fr' => 'Votre compte est banni. Contactez le support Gura & Gurisha.',
        ],
        'method_not_allowed' => [
            'rw' => 'Method not allowed',
            'en' => 'Method not allowed',
            'fr' => 'Méthode non autorisée',
        ],
        'invalid_action' => [
            'rw' => 'Invalid action',
            'en' => 'Invalid action',
            'fr' => 'Action invalide',
        ],
        'generic_error' => [
            'rw' => 'Ikosa ryabaye',
            'en' => 'Something went wrong',
            'fr' => 'Une erreur s’est produite',
        ],
        'email_invalid' => [
            'rw' => 'Email ntabwo ari yo',
            'en' => 'Email is not valid',
            'fr' => 'E-mail invalide',
        ],
        'nickname_required' => [
            'rw' => 'Nickname irakenewe',
            'en' => 'Nickname is required',
            'fr' => 'Le pseudo est obligatoire',
        ],
        'server_error' => [
            'rw' => 'Ikosa rya seriveri',
            'en' => 'Server error',
            'fr' => 'Erreur serveur',
        ],
    ];
}

function apiMsg(string $key, ?string $lang = null): string {
    $lang = $lang ?? requestLang();
    $catalog = apiMessageCatalog();
    if (!isset($catalog[$key])) {
        return $key;
    }
    return $catalog[$key][$lang] ?? $catalog[$key]['en'] ?? $key;
}

function jsonErrorKey(string $key, int $code = 400): void {
    jsonResponse([
        'success' => false,
        'error' => apiMsg($key),
        'error_key' => $key,
    ], $code);
}
