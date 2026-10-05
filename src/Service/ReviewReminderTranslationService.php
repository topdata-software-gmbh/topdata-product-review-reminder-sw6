<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Service;

/**
 * Locale copy for the reminder mail, read from this plugin's own snippet JSON.
 *
 * Why not the `trans` Twig filter or the `snippet` table: in Shopware 6.7 both
 * resolve exclusively from the `snippet` database table, scoped to a sales
 * channel's snippet set. Plugin JSON under `src/Resources/snippet/` is only
 * ever read by the admin snippet editor — `SnippetFileLoader` builds a
 * `SnippetFileCollection` for `SnippetController`/`SnippetValidator` and
 * nothing writes those files into the table. A mail rendered from the CLI or
 * the messenger worker therefore renders the literal key
 * "TopdataProductReviewReminderSW6.reviewReminderIntro" in the customer's
 * inbox, which is exactly what the first --preview run produced.
 *
 * So the mail resolves its own copy: the JSON file is the editable source of
 * truth, and BUILT_IN is a last-resort copy that keeps a send from breaking if
 * a file is missing or corrupt. The JSON file always wins.
 */
final class ReviewReminderTranslationService
{
    /**
     * Root key inside the snippet JSON. Kept as the plugin's snippet namespace
     * so the files stay editable in the admin snippet editor.
     */
    public const NAMESPACE_KEY = 'TopdataProductReviewReminderSW6';

    private const SNIPPET_FILE = 'topdata-product-review-reminder-sw6.json';

    private const DEFAULT_LOCALE = 'de-DE';

    private const FALLBACK_LOCALE = 'en-GB';

    /**
     * Shopware locale to shipped-locale mapping. The shop sells de-CH, fr-CH,
     * de-DE and en-GB, and the plugin ships files for de-DE, fr-CH and en-GB.
     *
     * fr-CH is its own locale rather than an alias of de-DE: the sales channels
     * sell to French-speaking customers, and silently handing them the German
     * copy is not an acceptable fallback for a mail that asks for a review.
     *
     * @var array<string, string>
     */
    private const LOCALE_MAP = [
        'de-CH' => 'de-DE',

        // focusshop.ch runs on the gsw-CH locale; its customers read German.
        'gsw-CH' => 'de-DE',
        'de' => 'de-DE',
        'de-AT' => 'de-DE',
        'fr-CH' => 'fr-CH',
        'fr' => 'fr-CH',
        'fr-FR' => 'fr-CH',
        'it-CH' => 'de-DE',
        'en-US' => 'en-GB',
        'en' => 'en-GB',
    ];

    /**
     * Last-resort copy, used only when a snippet file is missing, unreadable or
     * lacks the key. Keys are the leaf names of the JSON root above.
     *
     * @var array<string, array<string, string>>
     */
    private const BUILT_IN = [
        'de-DE' => [
            'reviewReminderSubject' => 'Bitte bewerten Sie Ihren Einkauf bei Focus Discount',
            'reviewReminderHeadline' => 'Ihre Meinung zählt',
            'reviewReminderIntro' => 'Hallo %firstName%, Sie haben bei uns eingekauft. Würden Sie uns eine kurze Bewertung zu Ihrer Bestellung %orderNumber% schenken?',
            'reviewReminderCta' => 'Jetzt bewerten',
            'reviewReminderFooter' => 'Sie erhalten diese Nachricht, weil Sie bei uns eingekauft haben.',
            'consentTitle' => 'Bewertungserinnerung',
            'consentLabel' => 'Ich möchte an die Bewertungen erinnert werden.',
            'consentHint' => 'Sie erhalten nach einer Bestellung eine E-Mail mit einem Link zur Bewertung. Sie können Ihre Zustimmung hier jederzeit widerrufen.',
            'consentSave' => 'Speichern',
            'consentSaving' => 'wird gespeichert …',
            'consentSaved' => 'gespeichert',
            'consentFailed' => 'Speichern nicht möglich – bitte den Knopf drücken.',
        ],
        'fr-CH' => [
            'reviewReminderSubject' => 'Veuillez évaluer votre achat chez Focus Discount',
            'reviewReminderHeadline' => 'Votre avis compte',
            'reviewReminderIntro' => 'Bonjour %firstName%, vous avez effectué un achat chez nous. Accepteriez-vous de nous laisser une courte évaluation de votre commande %orderNumber% ?',
            'reviewReminderCta' => 'Évaluer maintenant',
            'reviewReminderFooter' => 'Vous recevez ce message parce que vous avez effectué un achat chez nous.',
            'consentTitle' => 'Rappel d\'évaluation',
            'consentLabel' => 'Je souhaite recevoir un rappel pour laisser une évaluation.',
            'consentHint' => 'Après une commande, vous recevrez un e-mail contenant un lien pour laisser une évaluation. Vous pouvez retirer votre consentement ici à tout moment.',
            'consentSave' => 'Enregistrer',
            'consentSaving' => 'enregistrement…',
            'consentSaved' => 'enregistré',
            'consentFailed' => 'Enregistrement impossible – veuillez appuyer sur le bouton.',
        ],
        'en-GB' => [
            'reviewReminderSubject' => 'How was your order?',
            'reviewReminderHeadline' => 'Your opinion matters',
            'reviewReminderIntro' => 'Hi %firstName%, you recently shopped with us. Would you write a short review of your order %orderNumber%?',
            'reviewReminderCta' => 'Write a review',
            'reviewReminderFooter' => 'You are receiving this message because you placed an order with us.',
            'consentTitle' => 'Review reminder',
            'consentLabel' => 'I would like to be reminded about reviews.',
            'consentHint' => 'After an order you will receive an email with a link to leave a review. You can withdraw your consent here at any time.',
            'consentSave' => 'Save',
            'consentSaving' => 'saving …',
            'consentSaved' => 'saved',
            'consentFailed' => 'Could not save – please press the button.',
        ],
    ];

    private readonly string $snippetDir;

    /**
     * Loaded snippet files, keyed by shipped locale. Parsed at most once per
     * locale per process.
     *
     * @var array<string, array<string, string>>
     */
    private array $cache = [];

    public function __construct()
    {
        $this->snippetDir = \dirname(__DIR__) . '/Resources/snippet';
    }

    public function getSubject(string $locale): string
    {
        return $this->translate($locale, 'reviewReminderSubject');
    }

    /**
     * All copy for the mail body, already interpolated. Keys are the leaf names
     * under NAMESPACE_KEY.
     *
     * @param array<string, string> $params
     * @return array<string, string>
     */
    public function getLabels(string $locale, array $params = []): array
    {
        $shipped = $this->resolveShippedLocale($locale);

        // Precedence, highest first. The `+` operator keeps the LEFT operand on
        // a key collision, so the shipped snippet file has to come first and
        // the built-in copy of the fallback locale last.
        $strings = $this->loadFile($shipped)
            + (self::BUILT_IN[$shipped] ?? [])
            + $this->loadFile(self::FALLBACK_LOCALE)
            + self::BUILT_IN[self::FALLBACK_LOCALE];

        $labels = [];

        foreach ($strings as $key => $value) {
            $labels[$key] = $this->interpolate($value, $params);
        }

        return $labels;
    }

    /**
     * @param array<string, string> $params
     */
    public function translate(string $locale, string $key): string
    {
        return $this->getLabels($locale, ['%firstName%' => '', '%orderNumber%' => ''])[$key] ?? '';
    }

    /**
     * Maps a Shopware locale onto a locale that actually has a snippet file.
     */
    private function resolveShippedLocale(string $locale): string
    {
        if (isset(self::BUILT_IN[$locale])) {
            return $locale;
        }

        return self::LOCALE_MAP[$locale] ?? self::DEFAULT_LOCALE;
    }

    /**
     * @return array<string, string>
     */
    private function loadFile(string $shippedLocale): array
    {
        if (isset($this->cache[$shippedLocale])) {
            return $this->cache[$shippedLocale];
        }

        $path = $this->snippetDir . '/' . $shippedLocale . '/' . self::SNIPPET_FILE;
        $strings = [];

        if (is_file($path) && is_readable($path)) {
            $raw = file_get_contents($path);

            if ($raw !== false) {
                $decoded = json_decode($raw, true);

                // A flat file is accepted too, so the copy can be restructured
                // without breaking a send.
                $namespace = is_array($decoded) ? ($decoded[self::NAMESPACE_KEY] ?? $decoded) : null;

                if (is_array($namespace)) {
                    foreach ($namespace as $key => $value) {
                        if (is_string($value)) {
                            $strings[(string) $key] = $value;
                        }
                    }
                }
            }
        }

        return $this->cache[$shippedLocale] = $strings;
    }

    /**
     * @param array<string, string> $params
     */
    private function interpolate(string $value, array $params): string
    {
        return $params === [] ? $value : strtr($value, $params);
    }
}
