<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section\Advisory;

/** How a source in advisories.json is fetched. */
enum AdvisorySourceType: string
{
    /** RSS 2.0 or Atom feed at `url`. */
    case Feed = 'feed';

    /** Published security advisories of a public GitHub `repository`. */
    case Github = 'github';

    /** Messages in an IMAP `folder` that receives a mailing-list subscription. */
    case Imap = 'imap';

    /** Vulnerabilities of one `package` in an `ecosystem`, from the OSV.dev API. */
    case Osv = 'osv';

    /** CSAF 2.0 documents listed by a provider's ROLIE feed or changes.csv at `url`. */
    case Csaf = 'csaf';

    /**
     * Keys a source of this type accepts besides the common ones.
     *
     * @return list<string>
     */
    public function settingKeys(): array
    {
        return match ($this) {
            self::Feed, self::Csaf => ['url'],
            self::Github => ['repository', 'token_env'],
            self::Imap => ['host', 'port', 'encryption', 'username_env', 'password_env', 'folder', 'addresses'],
            self::Osv => ['ecosystem', 'package'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Feed => 'RSS/Atom',
            self::Github => 'GitHub',
            self::Imap => 'Mailbox',
            self::Osv => 'OSV',
            self::Csaf => 'CSAF',
        };
    }
}
