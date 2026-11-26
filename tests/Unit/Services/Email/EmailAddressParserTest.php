<?php

declare(strict_types=1);

use App\Services\Email\EmailAddressParser;
use App\Support\Email\EmailAddress;
use ZBateson\MailMimeParser\MailMimeParser;

test('should parse simple email', function (): void {
    $identities = (new EmailAddressParser('john@example.com'))->parse();

    $this->assertCount(1, $identities);
    $this->assertSame('john@example.com', $identities[0]->email);
    $this->assertNull($identities[0]->name);
});

test('should parse email with name', function (): void {
    $identities = (new EmailAddressParser('John Doe <john@example.com>'))->parse();

    $this->assertCount(1, $identities);
    $this->assertSame('john@example.com', $identities[0]->email);
    $this->assertSame('John Doe', $identities[0]->name);
});

test('should parse email with quoted name', function (): void {
    $identities = (new EmailAddressParser('"John Doe" <john@example.com>'))->parse();

    $this->assertCount(1, $identities);
    $this->assertSame('john@example.com', $identities[0]->email);
    $this->assertSame('John Doe', $identities[0]->name);
});

test('should parse email with parenthesised name', function (): void {
    $identities = (new EmailAddressParser('john@example.com (John Doe)'))->parse();

    $this->assertCount(1, $identities);
    $this->assertSame('john@example.com', $identities[0]->email);
    $this->assertSame('John Doe', $identities[0]->name);
});

test('should strip original marker', function (): void {
    $identities = (new EmailAddressParser('John Doe (original) <john@example.com>'))->parse();

    $this->assertCount(1, $identities);
    $this->assertSame('john@example.com', $identities[0]->email);
    $this->assertSame('John Doe', $identities[0]->name);
});

test('should collapse dotted email', function (): void {
    $identities = (new EmailAddressParser('john . doe@example.com'))->parse();

    $this->assertCount(1, $identities);
    $this->assertSame('john.doe@example.com', $identities[0]->email);
});

test('should invalidate malformed email', function (): void {
    $identities = (new EmailAddressParser('not-an-email'))->parse();

    $this->assertCount(1, $identities);
    $this->assertNull($identities[0]->email);
});

test('should skip short names', function (): void {
    $identities = (new EmailAddressParser('Jo <jo@example.com>'))->parse();

    $this->assertCount(1, $identities);
    $this->assertSame('jo@example.com', $identities[0]->email);
    $this->assertNull($identities[0]->name);
});

test('should skip name containing url', function (): void {
    $identities = (new EmailAddressParser('http://example.com <john@example.com>'))->parse();

    $this->assertSame('john@example.com', $identities[0]->email);
    $this->assertNull($identities[0]->name);
});

function parseSender(string $from): EmailAddress
{
    $header = (new MailMimeParser)->parse("From: $from\r\nSubject: x\r\n\r\nbody", false)->getHeader('from');

    return EmailAddressParser::sender($header);
}

test('the sender name is decoded when the mail client encoded it', function (string $from, string $name): void {
    expect(parseSender($from))->email->toBe('tim@example.com')->name->toBe($name);
})->with([
    'quoted-printable' => ['=?UTF-8?Q?Tim_D=C3=BCsterhus?= <tim@example.com>', 'Tim Düsterhus'],
    'base64' => ['=?utf-8?B?VGltIETDvHN0ZXJodXM=?= <tim@example.com>', 'Tim Düsterhus'],
]);

test('a comma in a quoted sender name is kept', function (): void {
    expect(parseSender('"Doe, John" <john@example.com>'))->email->toBe('john@example.com')->name->toBe('Doe, John');
});

test('a sender name written as a comment after the address is kept', function (): void {
    expect(parseSender('john@example.com (John Doe)'))->email->toBe('john@example.com')->name->toBe('John Doe');
});

test('a sender without a name has only an address', function (): void {
    expect(parseSender('john@example.com'))->email->toBe('john@example.com')->name->toBeNull();
});

test('an obfuscated sender address is still read', function (): void {
    expect(parseSender('John Doe <john . doe@example.com>'))->email->toBe('john.doe@example.com')->name->toBe('John Doe');
});

test('a sender name written before an unbracketed address is not merged into the address', function (): void {
    expect(parseSender('John Doe john@example.com'))->email->toBe('john@example.com')->name->toBe('John Doe');
});

test('a sender whose name is their address has no name', function (): void {
    expect(parseSender('"john@example.com" <john@example.com>'))->email->toBe('john@example.com')->name->toBeNull();
});

test('an address written in the sender name is not taken for the sender address', function (string $from): void {
    expect(parseSender($from))->email->toBe('john@example.com');
})->with([
    'nested comment' => ['Jane Doe (see (jane@example.com)) <john@example.com>'],
    'quote in a comment' => ['Jane Doe (a " quote, jane@example.com) <john@example.com>'],
    'unbracketed address' => ['"jane@example.com" john@example.com'],
    'comment after an unbracketed address' => ['john@example.com (jane@example.com)'],
]);

test('a sender name written as a comment before the address is kept', function (): void {
    expect(parseSender('"Jane Doe" (jane@example.com) <john@example.com>'))->email->toBe('john@example.com')->name->toBe('Jane Doe');
});

test('a sender header with several addresses has no sender address', function (string $from): void {
    expect(parseSender($from))->email->toBeNull();
})->with([
    'two senders' => ['Jane Doe <jane@example.com>, John Doe <john@example.com>'],
    'a bracketed and an unbracketed sender' => ['Jane Doe <jane@example.com>, john@example.com'],
    'two unbracketed addresses' => ['jane@example.com john@example.com'],
    'an address at an IP address' => ['Jane Doe <jane@[192.0.2.1]>, John Doe <john@example.com>'],
    'a dotless domain' => ['Jane Doe <jane@localhost>, John Doe <john@example.com>'],
    'a quoted local part' => ['Jane Doe <"jane.doe"@example.com>, John Doe <john@example.com>'],
    'separated by a semicolon' => ['jane@example.com; john@example.com'],
    'a group' => ['Team: jane@example.com, john@example.com;'],
    'two addresses in one bracket' => ['John Doe <jane@example.com, john@example.com>'],
    'an invalid second address' => ['jane@example.com, john@exämple.com'],
]);

test('a sender address written twice is one address', function (string $from): void {
    expect(parseSender($from))->email->toBe('john@example.com');
})->with([
    'same case' => ['john@example.com <john@example.com>'],
    'other case' => ['John@Example.com <john@example.com>'],
]);

test('a malformed sender header has no sender address', function (string $from): void {
    expect(parseSender($from))->email->toBeNull();
})->with([
    'cut by the mail parser in a comment' => ['(jane@example.com ' . str_repeat('a', 5000) . ') <john@example.com>'],
    'quote left open' => ['"a\\\\" jane@example.com" <john@example.com>'],
]);

test('a sender name that is another address is dropped', function (): void {
    expect(parseSender('"jane@example.com" <john@example.com>'))->email->toBe('john@example.com')->name->toBeNull();
});

test('an email without a usable sender address is kept, without an address as its name', function (string $from): void {
    expect(parseSender($from))->not->toBeNull()->email->toBeNull()->name->toBeNull();
})->with([
    'two addresses' => ['jane@example.com, john@example.com'],
    'an address as unquoted name' => ['jane@example.com <john@example.com>'],
    'an open comment after a bracketed address' => ['jane@example.com (<john@example.com>'],
]);
test('a stray closing parenthesis keeps the sender address', function (): void {
    expect(parseSender('John Doe) <john@example.com>'))->email->toBe('john@example.com');
});

test('a long sender name is cut to fit the database', function (): void {
    expect(parseSender('"' . str_repeat('a', 300) . '" <john@example.com>'))->name->toBe(str_repeat('a', 255));
});

test('an address in the sender name is not taken when the bracketed address is invalid', function (string $from): void {
    expect(parseSender($from))->email->toBeNull();
})->with([
    'no domain dot' => ['jane@example.com <john@localhost>'],
    'accented local part' => ['jane@example.com <jöhn@example.com>'],
]);

test('the name of a header with several senders names them all', function (): void {
    expect(parseSender('Jane Doe <jane@example.com>, John Doe <john@example.com>'))
        ->email->toBeNull()
        ->name->toBe('Jane Doe, John Doe');
});
test('the sender name is kept when the address is not usable', function (string $from, string $name): void {
    expect(parseSender($from))->email->toBeNull()->name->toBe($name);
})->with([
    'invalid address' => ['John Doe <john@localhost>', 'John Doe'],
    'open comment after the address' => ['"Jane Doe" <jane@example.com> (', 'Jane Doe'],
    'encoded words' => ['=?UTF-8?Q?J=C3=B6rg?= =?UTF-8?Q?_M=C3=BCller?= <jorg@localhost>', 'Jörg Müller'],
    'unknown charset' => ['=?bogus?Q?J=F6rg?= <jorg@localhost>', 'Jörg'],
]);
test('a sender name with an address in it is dropped', function (): void {
    expect(parseSender('"john.doe"@example.com'))->email->toBe('"john.doe"@example.com')->name->toBeNull();
});

test('the address in a sender name is left out of the name', function (): void {
    expect(parseSender('"Jane Doe <jane@example.com>" <john@example.com>'))->email->toBe('john@example.com')->name->toBe('Jane Doe');
});

test('a sender name written against the bracket is not merged into the address', function (): void {
    expect(parseSender('John Doe<john@example.com>'))->email->toBe('john@example.com');
});

test('a sender name with an @ but no address is kept', function (string $from, string $name): void {
    expect(parseSender($from))->email->toBe('john@example.com')->name->toBe($name);
})->with([
    'company' => ['John Doe @ Zend <john@example.com>', 'John Doe @ Zend'],
    'handle' => ['John Doe [@jdoe] <john@example.com>', 'John Doe [@jdoe]'],
    'handle in a quoted name' => ['"John Doe (@jdoe)" <john@example.com>', 'John Doe (@jdoe)'],
]);
test('a sender address bracketed twice is one address', function (string $from): void {
    expect(parseSender($from))->email->toBe('john@example.com')->name->toBe('John Doe');
})->with([
    'side by side' => ['John Doe <john@example.com> <JOHN@example.com>'],
    'as two mailboxes' => ['John Doe <john@example.com>, <john@example.com>'],
]);

test('a quoted local part holding another address is not taken', function (): void {
    expect(parseSender('<"jane@example.com"@example.net>'))->email->toBeNull();
});

test('an address hidden from the address count is still another address', function (string $from): void {
    expect(parseSender($from))->email->toBeNull();
})->with([
    'joined by a slash' => ['jane@example.com/john@example.com'],
    'spaces around a dot' => ['Jane Doe <jane@example.com>, john@example . com'],
    'spaces around a dot without brackets' => ['jane@example . com john@example.com'],
    'empty brackets' => ['jane@example.com <>'],
]);

test('senders named in comments are all named', function (string $from): void {
    expect(parseSender($from))->email->toBeNull()->name->toBe('Jane Doe, John Doe');
})->with([
    'both in comments' => ['jane@example.com (Jane Doe), john@example.com (John Doe)'],
    'one in a comment' => ['Jane Doe <jane@example.com>, john@example.com (John Doe)'],
]);

test('a sender name with a disguised address is dropped', function (string $from): void {
    expect(parseSender($from))->email->toBe('john@example.com')->name->toBeNull();
})->with([
    'spaces around the dot' => ['"jane@example . com" <john@example.com>'],
    'fullwidth at sign' => ['"jane＠example.com" <john@example.com>'],
]);

test('the sender name is that of the sender whose address is taken', function (string $from, ?string $name): void {
    expect(parseSender($from))->email->toBe('john@example.com')->name->toBe($name);
})->with([
    'other sender without an address' => ['Jane Doe <jane at example.com>, John Doe <john@example.com>', 'John Doe'],
    'other sender with empty brackets' => ['"Jane Doe" <>, <john@example.com>', null],
    'other sender with a name only' => ['Jane Doe, john@example.com', null],
]);

test('a sender name with an @ written before the brackets is kept, without an address', function (): void {
    expect(parseSender('John@Home <john@example.com>'))->email->toBeNull()->name->toBe('John@Home');
});

test('a second sender at a dotless domain or an IP address is still another sender', function (string $from): void {
    expect(parseSender($from))->email->toBeNull();
})->with([
    'dotless domain in brackets' => ['John Doe <john@example.com> <jane@localhost>'],
    'IP address in brackets' => ['John Doe <john@example.com> <jane@[192.0.2.1]>'],
    'IP address before an unbracketed address' => ['jane@[192.0.2.1] john@example.com'],
    'dotless domain before an unbracketed address' => ['jane@localhost john@example.com'],
]);
