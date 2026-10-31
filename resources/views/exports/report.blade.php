<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $document->title }}</title>
    <style>
        /* A PDF is printed, so the layout is deliberately plain and the fonts
           are the ones every viewer already has: nothing is fetched and nothing
           is embedded. Every value below is escaped output; an account name is
           data and never markup. */
        * { box-sizing: border-box; }

        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #1f2937;
            margin: 0;
            padding: 0;
        }

        .header {
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 10px;
            margin-bottom: 16px;
        }

        .company {
            font-size: 15px;
            font-weight: bold;
            color: #1e3a8a;
            letter-spacing: 0.4px;
        }

        .title {
            font-size: 13px;
            margin-top: 2px;
            color: #374151;
        }

        table.meta {
            width: 100%;
            margin-top: 8px;
            border-collapse: collapse;
        }

        table.meta td {
            padding: 1px 0;
            font-size: 9px;
            color: #4b5563;
        }

        table.meta td.label {
            width: 90px;
            font-weight: bold;
            color: #6b7280;
        }

        .section { margin-bottom: 14px; }

        .section h2 {
            font-size: 11px;
            color: #1e3a8a;
            margin: 0 0 5px 0;
            padding: 4px 6px;
            background-color: #eff6ff;
            border-left: 3px solid #2563eb;
        }

        table.grid {
            width: 100%;
            border-collapse: collapse;
        }

        table.grid th {
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #6b7280;
            border-bottom: 1px solid #d1d5db;
            padding: 4px 6px;
        }

        table.grid td {
            padding: 3px 6px;
            border-bottom: 1px solid #f3f4f6;
        }

        table.grid tr.total td {
            border-top: 1px solid #9ca3af;
            border-bottom: none;
            font-weight: bold;
            color: #111827;
            padding-top: 5px;
        }

        /* Amount columns are the right-hand ones in every section, and a
           figure that is not aligned on its decimal is not readable. */
        table.grid th.right,
        table.grid td.right { text-align: right; }

        .summary {
            border-top: 2px solid #1e3a8a;
            margin-top: 6px;
            padding-top: 8px;
        }

        table.summary {
            width: 100%;
            border-collapse: collapse;
        }

        table.summary td {
            padding: 3px 0;
            font-size: 11px;
        }

        table.summary td.label { color: #374151; }

        table.summary td.value {
            text-align: right;
            font-weight: bold;
            color: #1e3a8a;
            width: 140px;
        }

        .note {
            margin-top: 12px;
            padding: 6px 8px;
            background-color: #f9fafb;
            border-left: 3px solid #9ca3af;
            font-size: 8.5px;
            color: #6b7280;
            line-height: 1.4;
        }

        .generated {
            margin-top: 14px;
            font-size: 8px;
            color: #9ca3af;
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="company">{{ $document->meta[0]['value'] ?? '' }}</div>
        <div class="title">{{ $document->title }}</div>

        @if (count($document->meta) > 0)
            <table class="meta">
                @foreach ($document->meta as $line)
                    <tr>
                        <td class="label">{{ $line['label'] }}</td>
                        <td>{{ $line['value'] }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    @foreach ($document->sections as $section)
        <div class="section">
            <h2>{{ $section->heading }}</h2>

            <table class="grid">
                @if (count($section->columns) > 0)
                    <thead>
                        <tr>
                            @foreach ($section->columns as $index => $column)
                                <th @class(['right' => $index > 0])>{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                @endif

                <tbody>
                    @foreach ($section->rows as $row)
                        <tr>
                            @foreach ($row as $index => $cell)
                                <td @class(['right' => $index > 0])>{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach

                    @if ($section->total !== null)
                        <tr class="total">
                            @foreach ($section->total as $index => $cell)
                                <td @class(['right' => $index > 0])>{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    @endforeach

    @if (count($document->summary) > 0)
        <div class="summary">
            <table class="summary">
                @foreach ($document->summary as $line)
                    <tr>
                        <td class="label">{{ $line['label'] }}</td>
                        <td class="value">{{ $line['value'] }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @if ($document->note !== null && $document->note !== '')
        <div class="note">{{ $document->note }}</div>
    @endif

    <div class="generated">Generated {{ now()->format('Y-m-d H:i') }}</div>
</body>
</html>
