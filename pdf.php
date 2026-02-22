<?php
/**
 * CPE Monitor Activity Transcript PDF Parser
 * 
 * Usage:
 *   php parse_cpe_pdf.php path/to/transcript.pdf
 *   OR via HTTP: POST the PDF file as multipart/form-data field "pdf"
 * 
 * Requires: composer require smalot/pdfparser
 */

require_once __DIR__ . '/vendor/autoload.php';

use Smalot\PdfParser\Parser;

/**
 * Main parser class for CPE Monitor Activity Transcripts
 */
class CpePdfParser
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = new Parser();
    }

    /**
     * Parse a CPE Monitor PDF file and return structured JSON.
     *
     * @param  string $pdfPath  Absolute or relative path to the PDF file
     * @return string           JSON-encoded result
     */
    public function parseFile(string $pdfPath): string
    {
        if (!file_exists($pdfPath)) {
            return $this->error("File not found: $pdfPath");
        }

        try {
            $pdf        = $this->parser->parseFile($pdfPath);
            $pages      = $pdf->getPages();
            $allText    = '';

            foreach ($pages as $page) {
                $allText .= $page->getText() . "\n";
            }

            $result = $this->extractData($allText);
            return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Parse a CPE Monitor PDF from raw binary content (e.g. uploaded file).
     *
     * @param  string $content  Raw PDF binary content
     * @return string           JSON-encoded result
     */
    public function parseContent(string $content): string
    {
        try {
            $pdf     = $this->parser->parseContent($content);
            $pages   = $pdf->getPages();
            $allText = '';

            foreach ($pages as $page) {
                $allText .= $page->getText() . "\n";
            }

            $result = $this->extractData($allText);
            return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function extractData(string $rawText): array
    {
        // Normalize line endings and remove form-feed characters
        $text = str_replace(["\r\n", "\r", "\f"], "\n", $rawText);

        return [
            'header'     => $this->extractHeader($text),
            'activities' => $this->extractActivities($text),
            'disclaimer' => $this->extractDisclaimer($text),
        ];
    }

    // ---- Header fields -------------------------------------------------------
    // Designed to work with any CPE Monitor transcript layout: extract from header
    // block when present, then apply full-document fallbacks for missing fields.

    private function extractHeader(string $text): array
    {
        $header = [
            'participant_name'        => null,
            'nabp_eprofile_id'        => null,
            'cpe_activity_date_range' => null,
            'total_cpe_hours_earned'  => null,
            'report_generated_at'     => null,
        ];

        // Optional header block: "CPE Monitor Activity Transcript" … until "Reported Generated"
        $block = null;
        if (preg_match('/CPE Monitor Activity Transcript\s*(.*?)(?=Reported?\s+Generated\s*@|$)/is', $text, $m)) {
            $block = trim($m[1]);
        }
        if ($block !== null && $block !== '') {
            if (preg_match('/\b(\d{1,2}\/\d{1,2}\/\d{4}\s+to\s+\d{1,2}\/\d{1,2}\/\d{4})\b/', $block, $m)) {
                $header['cpe_activity_date_range'] = trim($m[1]);
            }
            if (preg_match('/\b(\d+\.\d{2})\b/', $block, $m)) {
                $header['total_cpe_hours_earned'] = (float) $m[1];
            }
            if (preg_match('/\b(\d{6})\b/', $block, $m)) {
                $header['nabp_eprofile_id'] = $m[1];
            }
            $lines = preg_split('/\n+/', $block);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || preg_match('/^(Participant|NABP|CPE|Total|Recorded|If it)/i', $line)) {
                    continue;
                }
                if (preg_match('/^\d|^\d+\/\d+|\.\d+/', $line)) {
                    continue;
                }
                if (preg_match('/^[A-Z][a-z]+(?:\s+[A-Z][a-z]+){1,3}$/', $line)) {
                    $header['participant_name'] = $line;
                    break;
                }
            }
        }

        // Full-document fallbacks (different PDFs put name/ID in different places)
        if ($header['cpe_activity_date_range'] === null && preg_match('/\b(\d{1,2}\/\d{1,2}\/\d{4}\s+to\s+\d{1,2}\/\d{1,2}\/\d{4})\b/', $text, $m)) {
            $header['cpe_activity_date_range'] = trim($m[1]);
        }
        if ($header['total_cpe_hours_earned'] === null && preg_match('/Recorded CPE activity[^.]*\.\s*(\d+\.\d{2})\b/', $text, $m)) {
            $header['total_cpe_hours_earned'] = (float) $m[1];
        }
        if ($header['nabp_eprofile_id'] === null && preg_match('/\b(\d{6})\b/', $text, $m)) {
            $header['nabp_eprofile_id'] = $m[1];
        }
        if ($header['participant_name'] === null) {
            $lines = preg_split('/\n+/', $text);
            $afterParticipant = false;
            foreach ($lines as $line) {
                $line = trim($line);
                if (preg_match('/Participant\s+Name/i', $line)) {
                    $afterParticipant = true;
                    continue;
                }
                if ($afterParticipant && $line !== '' && !preg_match('/^(NABP|CPE|Total|Recorded|If it|Disclaimer|\d)/i', $line)
                    && !preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}/', $line)
                    && preg_match('/^[A-Z][a-z]+(?:\s+[A-Z][a-z]+){1,3}$/', $line)) {
                    $header['participant_name'] = $line;
                    break;
                }
                if ($afterParticipant && preg_match('/\d{1,2}\/\d{1,2}\/\d{4}\s+\d/', $line)) {
                    break;
                }
            }
        }

        $allMatches = [];
        if (preg_match_all(
            '/Reported?\s+Generated\s*@\s*(.+?)(?:\s+Page\s+\d+\s+Of\s+\d+)?(?:\n|$)/i',
            $text,
            $allMatches
        )) {
            $last = end($allMatches[1]);
            $header['report_generated_at'] = trim($last);
        }

        return $header;
    }

    // ---- Activity rows -------------------------------------------------------
    // Works with any CPE transcript: find table body (after "Live Hours Home Hours")
    // or use full text with footer/disclaimer stripped so all date-started rows are found.

    private function extractActivities(string $text): array
    {
        $activities = [];
        $datePattern = '\d{1,2}\/\d{1,2}\/\d{4}';

        // Strip footer and disclaimer everywhere
        $clean = preg_replace('/Reported?\s+Generated\s*@.*?Page\s+\d+\s+Of\s+\d+/is', '', $text);
        $clean = preg_replace('/Disclaimer\s*:.*$/is', '', $clean);
        $clean = trim($clean);

        // Prefer body after first "Live Hours Home Hours" (standard table header)
        $bodyText = $clean;
        if (preg_match('/Live\s+Hours\s+Home\s+Hours/i', $clean)) {
            $split = preg_split('/Live\s+Hours\s+Home\s+Hours/i', $clean, 2);
            if (isset($split[1])) {
                $bodyText = trim($split[1]);
            }
        }

        // Split into chunks: each starts at a new line that begins with a date
        $parts = preg_split("/(?=\n\s*(?:$datePattern)[\s\t])/", $bodyText);

        foreach ($parts as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $activity = $this->parseActivityChunk($chunk);
            if ($activity !== null) {
                $activities[] = $activity;
            }
        }

        // If no activities found, try full text (some PDFs put table before header block)
        if (count($activities) === 0 && $bodyText !== $clean) {
            $parts = preg_split("/(?=\n\s*(?:$datePattern)[\s\t])/", $clean);
            foreach ($parts as $chunk) {
                $chunk = trim($chunk);
                if ($chunk === '') {
                    continue;
                }
                $activity = $this->parseActivityChunk($chunk);
                if ($activity !== null) {
                    $activities[] = $activity;
                }
            }
        }

        return $activities;
    }

    private function parseActivityChunk(string $chunk): ?array
    {
        // Collapse multiple spaces / newlines within the chunk into single spaces
        $flat = preg_replace('/\s+/', ' ', $chunk);

        /*
         * Expected column order (all on one logical line after collapsing):
         *   ActivityDate  ActivityNumber  CreditType  Source  Title  Topic  Provider  LiveHours  HomeHours
         *
         * The tricky columns are Title, Topic, and Provider – they contain free
         * text.  We anchor on the known Credit Types (ACPE / IPCE) and on the
         * two decimal numbers at the end for Live/Home Hours.
         */

        // Must start with a date
        if (!preg_match('/^(\d{1,2}\/\d{1,2}\/\d{4})/', $flat, $dateMatch)) {
            return null;
        }
        $activityDate = $dateMatch[1];
        $rest = ltrim(substr($flat, strlen($activityDate)));

        // Activity # (may contain space when PDF line-wraps e.g. "JA0002895-0000-24-072- H01-P"), then Credit Type and Source
        if (!preg_match('/^(.+?)\s+(ACPE|IPCE)\s+(ACPE|IPCE)\s+/', $rest, $topMatch)) {
            return null;
        }
        $activityNumber = trim($topMatch[1]);
        $creditType     = $topMatch[2];
        $source         = $topMatch[3];
        $rest           = ltrim(substr($rest, strlen($topMatch[0])));

        // Live Hours and Home Hours are always the last two numbers (allow integer or decimal).
        // Require at least one space between them so we don't split e.g. "30.75" into 30.7 and 5.
        if (!preg_match('/(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s*$/', $rest, $hoursMatch)) {
            return null;
        }
        $liveHours = (float) $hoursMatch[1];
        $homeHours = (float) $hoursMatch[2];

        // Everything before the hours is: Title  Topic  Provider
        $middle = trim(substr($rest, 0, strrpos($rest, $hoursMatch[0])));

        // Normalize PDF line-break artifacts (e.g. "Substan ce" -> "Substance")
        $middleNormalized = preg_replace('/Substan\s+ce\b/', 'Substance', $middle);

        // Known Topic values seen in CPE Monitor transcripts (order matters: longer first)
        $knownTopics = [
            'Opioids/Pain Management/Substance Use Disorder',
            'Disease State Management/Drug Therapy',
            'Law Related to Pharmacy Practice',
            'Medication Therapy Management',
            'Pharmacy Administration',
            'Additional Topic Areas',
            'Patient Safety',
            'Drug Information',
            'Pharmacy Informatics',
            'Public Health',
            'HIV/AIDS',
            'Immunizations',
            'Compounding',
        ];

        // Try to split Title | Topic | Provider using normalized middle
        $title    = null;
        $topic    = null;
        $provider = null;

        foreach ($knownTopics as $knownTopic) {
            $topicPattern = preg_replace('/\s+/', '\s+', preg_quote($knownTopic, '/'));
            if (preg_match('/^(.+?)\s+(' . $topicPattern . ')\s+(.+)$/i', $middleNormalized, $tmatch)) {
                $title    = trim($tmatch[1]);
                $topic    = $knownTopic; // use canonical topic name
                $provider = trim($tmatch[3]);
                break;
            }
        }

        // If topic not matched by known list, use a heuristic: last "word group"
        // before the last big token is the provider; middle section is topic.
        if ($title === null) {
            // Fallback: split into thirds roughly
            $words  = explode(' ', $middle);
            $count  = count($words);
            $third  = max(1, (int) ($count / 3));
            $title    = implode(' ', array_slice($words, 0, $third));
            $topic    = implode(' ', array_slice($words, $third, $third));
            $provider = implode(' ', array_slice($words, $third * 2));
        }

        return [
            'activity_date'   => $activityDate,
            'activity_number' => $activityNumber,
            'credit_type'     => $creditType,
            'source'          => $source,
            'title'           => $title,
            'topic'           => $topic,
            'provider'        => $provider,
            'live_hours'      => $liveHours,
            'home_hours'      => $homeHours,
        ];
    }

    // ---- Disclaimer ----------------------------------------------------------

    private function extractDisclaimer(string $text): ?string
    {
        if (preg_match('/Disclaimer\s*:\s*(.*?)(?:Reported?\s+Generated\s*@|$)/is', $text, $m)) {
            return trim(preg_replace('/\s+/', ' ', $m[1]));
        }
        return null;
    }

    // ---- Utility -------------------------------------------------------------

    private function error(string $message): string
    {
        return json_encode(['error' => $message], JSON_PRETTY_PRINT);
    }
}


// =============================================================================
// Entry-point logic (CLI or HTTP)
// =============================================================================

$cpeParser = new CpePdfParser();

// --- HTTP mode ---------------------------------------------------------------
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Accept multipart upload (field name: "pdf")
        if (isset($_FILES['pdf']) && $_FILES['pdf']['error'] === UPLOAD_ERR_OK) {
            echo $cpeParser->parseFile($_FILES['pdf']['tmp_name']);
        }
        // Accept raw PDF body
        elseif (
            isset($_SERVER['CONTENT_TYPE']) &&
            str_contains($_SERVER['CONTENT_TYPE'], 'application/pdf')
        ) {
            $content = file_get_contents('php://input');
            echo $cpeParser->parseContent($content);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'No PDF provided. POST with multipart field "pdf" or raw PDF body.']);
        }
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Only POST requests are accepted.']);
    }
    exit;
}

// --- CLI mode ----------------------------------------------------------------
if ($argc < 2) {
    fwrite(STDERR, "Usage: php pdf.php <path-to-pdf>\n");
    exit(1);
}

$pdfPath = $argv[1];
$json    = $cpeParser->parseFile($pdfPath);
echo $json;
echo PHP_EOL;

// Save JSON to file (same basename as PDF, .json extension)
$baseName = pathinfo($pdfPath, PATHINFO_FILENAME);
$outPath  = (dirname($pdfPath) !== '.' ? dirname($pdfPath) . '/' : '') . $baseName . '.json';
if (file_put_contents($outPath, $json . "\n") !== false) {
    fwrite(STDERR, "Saved to $outPath\n");
}