<?php

define('APPLICATION_BASE_PATH', realpath(__DIR__ . '/..'));

spl_autoload_register(function ($className) {
    $namespaces = explode('\\', $className);
    if (count($namespaces) > 1) {
        $classPath
            = APPLICATION_BASE_PATH
            . '/vendor/'
            . implode('/', $namespaces)
            . '.php';
        if (file_exists($classPath)) {
            require_once($classPath);
        }
    }
});

include_once APPLICATION_BASE_PATH . '/vendor/Mustache/Mustache.php';
include_once APPLICATION_BASE_PATH . '/vendor/smartypants/smartypants.php';
include_once APPLICATION_BASE_PATH . '/vendor/markdown-extra/markdown.php';
include_once APPLICATION_BASE_PATH . '/vendor/lessphp/lessc.inc.php';
include_once APPLICATION_BASE_PATH . '/vendor/simpledom/simple_html_dom.php';

use Assetic\Asset\AssetCollection;
use Assetic\Asset\FileAsset;
use Assetic\Asset\GlobAsset;
use Assetic\Filter;

// Setup the command line options
$shortopts
  = "s:" // source
  . "r"  // refresh
  . "p"; // pdf output

$longopts  = array(
    "source:",
    "refresh",
    "pdf"
);

$options = getopt($shortopts, $longopts);

// Combine the options to their shorter names
if (empty($options['s']) && !empty($options['source'])) {
    $options['s'] = $options['source'];
}
$refresh_dev = isset($options['r']) || isset($options['refresh']);

if (!isset($options['s'])) {
    exit("Please specify a source document: build.php -s resume/resume.pdf\n");
}

$basename     = pathinfo($options['s'], PATHINFO_FILENAME);
$source       = './' . $options['s'];
$pdf_source   = './../resume/' . $basename . '-print.html';
$output_md  = './../resume/index.md';
$output_html  = './../resume/' . $basename . '.html';
$output_css   = './../css/' . $basename . '-resume.css';
$pdf_output   = './../resume/' . $basename . '.pdf';

$css = new AssetCollection(
    array(
        new GlobAsset(APPLICATION_BASE_PATH . '/assets/css/*.css')
    ),
    array(
        new Filter\LessphpFilter(),
    )
);
$style = $css->dump();

$jekyll_templt = file_get_contents(APPLICATION_BASE_PATH . '/assets/templates/jekyll_template.html');
$html_template = file_get_contents(APPLICATION_BASE_PATH . '/assets/templates/default.html');
$css_template  = file_get_contents(APPLICATION_BASE_PATH . '/assets/templates/resume_style.css');
$resume        = file_get_contents($source);

// Process with Markdown, and then use SmartyPants to clean up punctuation.
$resume = SmartyPants(Markdown($resume));

// We'll construct the title for the html document from the h1 and h2 tags
$html = str_get_html($resume);
$title = sprintf(
    '%s | %s',
    $html->find('h1', 0)->innertext,
    $html->find('h2', 0)->innertext
);

$m = new Mustache;

// We'll now render the Markdown into a file with Mustache Templates
$rendered_markdown = $m->render(
    $jekyll_templt,
    array(
        'resume' => $resume,
    )
);
// Save the fully rendered markdown to the final destination
file_put_contents(
    $output_md,
    $rendered_markdown
);
echo "Wrote markdown to $output_md\n";

// We'll now render the HTML into a file with Mustache Templates
$rendered_html = $m->render(
    $html_template,
    array(
        'title'  => $title,
        'cssfile'=> $output_css,
        // 'cssfile'=> $style,
        'resume' => $resume,
        'reload' => $refresh_dev
    )
);
// Save the fully rendered html to the final destination
file_put_contents(
    $output_html,
    $rendered_html
);
echo "Wrote html to $output_html\n";

// We'll now render the CSS into a file with Mustache Templates
$rendered_css = $m->render(
    $css_template,
    array(
        'style'  => $style
    )
);
// Save the fully rendered css to the final destination
file_put_contents(
    $output_css,
    $rendered_css
);
echo "Wrote css to $output_css\n";



// If the user wants to make a pdf file, we'll use wkhtmltopdf to convert
// the html document into a nice looking pdf.
if (isset($options['pdf'])) {

    // The pdf needs some extra css rules, and so we'll add them here
    // to our html document
    $pdf_classed = str_replace(
        'body class=""',
        'body class="pdf"',
        $rendered_html
    );

    // Save the new pdf-ready html to a temp destination
    file_put_contents(
        $pdf_source,
        $pdf_classed
    );

    echo "\ncommand (might freeze): wkhtmltopdf $pdf_source $pdf_output\n";
    // Process the document with wkhtmltopdf
    exec(
        'wkhtmltopdf '
        . $pdf_source .' '
        . $pdf_output
    );

    // Unlink the temporary file
    // unlink($pdf_source);
    echo "Wrote pdf to $pdf_output\n";
}

/* End of file build.php */
