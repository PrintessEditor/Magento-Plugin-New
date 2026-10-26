<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Config;

class FriendlyOptionLabelConstants
{
    public const OPTION_LABEL = 'option_label';
    public const OPTION_VALUE = 'option_value';
    public const FRIENDLY_TEXT = 'friendly_text';

    /**
     * Leave "Option Value" blank in a rule row to relabel the option's label itself
     * (e.g. DOCUMENT_SIZE -> Document Size). Fill it in to relabel one specific value
     * of that option (e.g. COVER_TYPE / HC -> Hard Cover).
     */
    public const OPTION_VALUE_ANY = '';
}
