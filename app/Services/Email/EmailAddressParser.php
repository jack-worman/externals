<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Support\Email\EmailAddress;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\IHeader;
use ZBateson\MailMimeParser\Header\Part\AddressPart;

/**
 * Parses the loose addresses found in old emails: "john@example.com (John Doe)", "john . doe@example.com"…
 *
 * @see https://github.com/madewithlove/why-cant-we-have-nice-things/blob/master/src/Services/IdentityExtractor.php
 */
class EmailAddressParser
{
    /** @var array<int, ?string> */
    private array $emails = [];

    /** @var array<int, string> */
    private array $names = [];

    public function __construct(
        private readonly string $string,
    ) {}

    /**
     * The sender of an email. The mail parser decodes names ("=?UTF-8?Q?…?=") and quoted names
     * ("Doe, John"), and this parser fills in what it misses, e.g. names written as a comment.
     * The address is that of the only sender, read by this parser first: the mail parser merges an unbracketed name into it.
     */
    public static function sender(IHeader $from): EmailAddress
    {
        $loose = (new self($from->getRawValue()))->parse()[0] ?? null;
        $mailboxes = $from instanceof AddressHeader ? $from->getAddresses() : [];

        $email = self::senderAddress($from);
        if ($email !== null) {
            // The name of the sender whose address this is, which isn't the first one when another has no address
            $sender = array_find($mailboxes, fn(AddressPart $address) => mb_strtolower($address->getEmail()) === mb_strtolower($email));
            $names = [mb_trim((string) ($sender?->getName() ?? (count($mailboxes) === 1 ? $mailboxes[0]->getName() : '')))];
        } else {
            // Without an address, the names of all the senders: the header may name several
            $names = array_map(fn(AddressPart $address) => mb_trim($address->getName()), $mailboxes);
            // Unnamed senders may be named in a comment: "jane@example.com (Jane Doe), john@example.com (John Doe)"
            if (in_array('', $names, true) && $from instanceof AddressHeader) {
                $names = [...$names, ...array_map(mb_trim(...), $from->getComments())];
            }
        }
        $names = array_unique(array_filter($names, fn(string $name) => $name !== '' && ! self::holdsAddress($name)));
        // The loose parser reads the name of a lone sender written as a comment or before an unbracketed address
        $name = $names !== [] ? implode(', ', $names) : (count($mailboxes) <= 1 ? $loose?->name : null);
        // A name with an address in it would pass for the sender's address
        if ($name !== null && self::holdsAddress($name)) {
            $name = null;
        }

        return new EmailAddress($email, $name !== null ? mb_substr($name, 0, 255) : null);
    }

    /**
     * The address of the only sender, outside the quoted name and the comments: anyone can write an address in
     * their name, "Jane Doe" (jane@example.com) <john@example.com> is John. Nobody can tell which of several sent it.
     */
    private static function senderAddress(IHeader $from): ?string
    {
        $value = preg_replace('/"(?:[^"\\\\]++|\\\\.)*+"|(?<comment>\((?:[^()\\\\]++|\\\\.|(?&comment))*+\))/s', ' ', $from->getRawValue());
        // A quote or comment left open means a malformed header, or one the mail parser cut short
        if ($value === null || strpbrk($value, '"(') !== false) {
            return null;
        }
        // Every word with an @ counts, even an invalid address such as "jane@localhost": it may still be the sender's.
        // A handle such as "@derickr" doesn't. Old addresses can have spaces around their dots: "john@example . com".
        preg_match_all('/[^\s<>()\[\],;:@\/]+@[^\s<>(),;:@\/]+/', (string) preg_replace('/\s*\.\s*/', '.', $value), $words);
        if (count(array_unique(array_map(mb_strtolower(...), $words[0]))) > 1) {
            return null;
        }
        // The mail parser also sees the senders whose address isn't shaped like one: "jane@[192.0.2.1]", "jane@localhost"
        $mailboxes = $from instanceof AddressHeader ? array_map(fn(AddressPart $address) => mb_strtolower($address->getEmail()), $from->getAddresses()) : [];
        if (count(array_unique(array_filter($mailboxes, fn(string $mailbox) => str_contains($mailbox, '@')))) > 1) {
            return null;
        }
        // The bracketed address, so that a name written against the bracket isn't merged into it
        if (preg_match_all('/<([^<>]*)>/', $value, $brackets) > 0) {
            $value = implode(' ', $brackets[1]);
            // The mail parser would take an address written in the name for the empty brackets
            if (! str_contains($value, '@')) {
                return null;
            }
        }

        return self::addresses($value)[0] ?? self::mailParserAddress($from);
    }

    /**
     * The mail parser reads the addresses this parser can't, such as those with a quoted local part.
     */
    private static function mailParserAddress(IHeader $from): ?string
    {
        $parsed = $from instanceof AddressHeader ? $from->getAddresses() : [];
        $email = count($parsed) === 1 ? $parsed[0]->getEmail() : null;

        // A quoted local part can hold another address: "jane@example.com"@example.net
        return filter_var($email, FILTER_VALIDATE_EMAIL) && mb_substr_count($email, '@') === 1 ? $email : null;
    }

    /**
     * Also finds an address disguised with spaces around its dots or a fullwidth "＠": "jane@example . com".
     */
    private static function holdsAddress(string $text): bool
    {
        $text = str_replace(['＠', '﹫', '．', '｡'], ['@', '@', '.', '.'], $text);

        return self::addressLikeWords((string) preg_replace('/\s*\.\s*/', '.', $text)) !== [];
    }

    /**
     * @return list<string> The words shaped like an address, valid or not, but not handles such as "@derickr"
     */
    private static function addressLikeWords(string $text): array
    {
        preg_match_all('/[^\s<>()\[\],;:@\/]+@[^\s<>()\[\],;:@\/]+\.[^\s<>()\[\],;:@\/]+/', $text, $words);

        return $words[0];
    }

    /**
     * @return list<string> The valid addresses found in the text
     */
    private static function addresses(string $text): array
    {
        return array_values(array_filter(array_map(fn(EmailAddress $address) => $address->email, (new self($text))->parse())));
    }

    /**
     * @return EmailAddress[]
     */
    public function parse(): array
    {
        $emails = str_replace('(original)', '', $this->string);
        $emails = preg_replace('/[<>\(\)]/', '', $emails);
        $emails = str_replace(' . ', '.', $emails);

        $names = $this->extractEmails($emails);
        $this->extractNames($names);

        $this->emails = array_values(array_filter($this->emails));
        $this->names = array_values(array_filter($this->names));

        $identities = [];
        $count = count($this->emails) ?: count($this->names);

        for ($i = 0; $i < $count; $i++) {
            $email = $this->emails[$i] ?? null;
            if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $email = null;
            }

            $identities[] = new EmailAddress($email, $this->names[$i] ?? null);
        }

        return $identities;
    }

    private function extractEmails(string $emails): string
    {
        $names = $emails;
        $emails = preg_split('/[\s,\/]+/', $emails) ?: [];

        foreach ($emails as $key => $email) {
            $email = $this->trimCharacters($email);
            $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
            if ($email) {
                $names = str_replace($email, '', $names);
            }
            $this->emails[$key] = $email;
        }

        return $names;
    }

    private function trimCharacters(string $string): string
    {
        return mb_trim($string, ' /"=');
    }

    private function extractNames(string $names): void
    {
        $names = preg_split('/(,|  |\n)/', $names) ?: [];
        $names = array_filter($names);

        foreach ($names as $key => $name) {
            $name = $this->trimCharacters($name);

            if (mb_strpos($name, 'Watson Research')
                || mb_strlen($name) <= 3
                || str_contains($name, '?')
                || str_contains($name, 'http')
            ) {
                continue;
            }

            $this->names[$key] = $name;
        }
    }
}
