{{-- The plain-text alternative Content() points at: MailLayout::wrap() builds
     the string, this view exists only because Laravel's Content only takes a
     view name for the text part, never a raw string. Output raw — the string
     is already plain text, and {{ }} would HTML-escape characters (&, <, >)
     that have no business being escaped here. --}}
{!! $text !!}
