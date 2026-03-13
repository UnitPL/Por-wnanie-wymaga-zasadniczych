<?php
/**
 * Plugin Name:  Annex Diff Viewer
 * Plugin URI:   https://example.com/annex-diff
 * Description:  Side-by-side comparison of EU Machinery Directive Annex I (2006/42/WE) and Annex III (EU) 2023/1230 — VS Code–style diff with synchronized scrolling, aligned sections, and markdown rendering.
 * Version:      1.0.0
 * Author:       Machine Safety Team
 * Text Domain:  annex-diff
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'ANNEX_DIFF_DIR', plugin_dir_path( __FILE__ ) );
define( 'ANNEX_DIFF_URL', plugin_dir_url( __FILE__ ) );

/**
 * Parse a pre-written HTML file into sections by heading tags (h1–h6).
 * Differences are manually marked with <mark class="del"> / <mark class="ins">.
 */
function annex_diff_parse_html_sections( $html ) {
    if ( ! trim( $html ) ) {
        return array( 'preamble_html' => '', 'sections' => array() );
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors( true );
    $dom->loadHTML( '<meta charset="utf-8"><body>' . $html . '</body>', LIBXML_HTML_NODEFDTD );
    libxml_clear_errors();

    $body = $dom->getElementsByTagName( 'body' )->item( 0 );
    if ( ! $body ) return array( 'preamble_html' => '', 'sections' => array() );

    $heading_tags  = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );
    $sections      = array();
    $preamble_html = '';
    $current       = null;
    $current_html  = '';

    foreach ( $body->childNodes as $node ) {
        $tag = strtolower( $node->nodeName ?? '#text' );

        // Skip whitespace-only text nodes
        if ( $tag === '#text' && ! trim( $node->textContent ) ) continue;

        if ( in_array( $tag, $heading_tags, true ) ) {
            // Save previous section / preamble
            if ( $current !== null ) {
                $current['html'] = trim( $current_html );
                $sections[]      = $current;
            } elseif ( $current_html ) {
                $preamble_html = trim( $current_html );
            }

            $heading_text = $node->textContent;
            $number       = null;
            // data-pair attribute overrides numeric extraction for explicit matching
            if ( $node->hasAttribute( 'data-pair' ) ) {
                $number = $node->getAttribute( 'data-pair' );
            } elseif ( preg_match( '/^(\d+(?:\.\d+)*)[.\s]/', $heading_text, $nm ) ) {
                $number = $nm[1];
            }

            $current      = array(
                'level'       => (int) $tag[1],
                'heading'     => $heading_text,
                'heading_html' => trim( $heading_text ) ? $dom->saveHTML( $node ) : '',
                'number'      => $number,
                'html'        => '',
            );
            $current_html = '';
        } else {
            $current_html .= $dom->saveHTML( $node );
        }
    }

    if ( $current !== null ) {
        $current['html'] = trim( $current_html );
        $sections[]      = $current;
    } elseif ( $current_html ) {
        $preamble_html = trim( $current_html );
    }

    return array(
        'preamble_html' => $preamble_html,
        'sections'      => $sections,
    );
}

/* ──────────────────────────────────────────────
   SHORTCODE  [annex_diff]
   ────────────────────────────────────────────── */
add_shortcode( 'annex_diff', 'annex_diff_render' );

function annex_diff_render( $atts ) {
    $a = shortcode_atts( [
        'left'     => 'left.html',
        'right'    => 'right.html',
        'left_pl'  => 'left_pl.html',
        'right_pl' => 'right_pl.html',
    ], $atts );

    $left_path  = ANNEX_DIFF_DIR . 'data/' . basename( $a['left'] );
    $right_path = ANNEX_DIFF_DIR . 'data/' . basename( $a['right'] );

    if ( ! file_exists( $left_path ) || ! file_exists( $right_path ) ) {
        return '<p style="color:red">Annex Diff: data files not found.</p>';
    }

    $left_text  = file_get_contents( $left_path );
    $right_text = file_get_contents( $right_path );

    // Parse sections from static HTML files
    $left_parsed  = annex_diff_parse_html_sections( $left_text );
    $right_parsed = annex_diff_parse_html_sections( $right_text );

    // Polish versions (optional)
    $left_pl_path  = ANNEX_DIFF_DIR . 'data/' . basename( $a['left_pl'] );
    $right_pl_path = ANNEX_DIFF_DIR . 'data/' . basename( $a['right_pl'] );
    $has_pl = file_exists( $left_pl_path ) && file_exists( $right_pl_path );

    $left_pl_js  = 'null';
    $right_pl_js = 'null';
    if ( $has_pl ) {
        $left_pl_parsed  = annex_diff_parse_html_sections( file_get_contents( $left_pl_path ) );
        $right_pl_parsed = annex_diff_parse_html_sections( file_get_contents( $right_pl_path ) );
        $left_pl_js  = json_encode( $left_pl_parsed,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $right_pl_js = json_encode( $right_pl_parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    }

    // Pass pre-rendered section data to JS
    $left_js  = json_encode( $left_parsed,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    $right_js = json_encode( $right_parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

    ob_start(); ?>
<!-- ═══════════════════════════════════════════════════════════
     ANNEX DIFF VIEWER  ·  root container
═══════════════════════════════════════════════════════════ -->
<style id="annex-diff-style">
/* ── reset & scope ───────────────────────────────────── */
#annex-diff-root *{box-sizing:border-box;margin:0;padding:0}
#annex-diff-root{
    --bg:       #1e1e1e;
    --surface:  #252526;
    --surface2: #2d2d30;
    --border:   #3e3e42;
    --text:     #d4d4d4;
    --text-dim: #858585;
    --text-hdr: #9cdcfe;
    --accent-l: #4ec9b0;
    --accent-r: #ce9178;
    --del-bg:   rgba(255,80,80,.13);
    --del-word: rgba(255,80,80,.45);
    --del-text: #f97171;
    --ins-bg:   rgba(80,200,100,.10);
    --ins-word: rgba(80,200,100,.40);
    --ins-text: #73c991;
    --mod-minor-bg:   rgba(120,220,140,.25);
    --mod-minor-text: #b0e8be;
    --mod-major-bg:   rgba(0,210,80,.55);
    --mod-major-text: #d4ffdf;
    --del-minor-bg:   rgba(255,120,120,.25);
    --del-minor-text: #f7b0b0;
    --del-major-bg:   rgba(255,50,50,.55);
    --del-major-text: #ffe0e0;
    --eq-dim:   #3e3e42;
    --scroll-thumb: #555;
    --radius:   4px;
    font-family:'Fira Code','JetBrains Mono','Consolas',monospace;
    font-size:13px;
    color:var(--text);
    background:var(--bg);
    width:100%;
    overflow:hidden;
}

/* ── toolbar ─────────────────────────────────────────── */
#annex-diff-toolbar{
    display:flex;align-items:center;justify-content:space-between;
    background:var(--surface2);
    border-bottom:1px solid var(--border);
    padding:10px 16px;
    gap:12px;
    flex-wrap:wrap;
}
.diff-toolbar-title{
    font-size:11px;font-weight:600;letter-spacing:.08em;
    text-transform:uppercase;color:var(--text-dim);
    white-space:nowrap;
}
.diff-stats{
    display:flex;gap:16px;font-size:11px;
}
.diff-stat-del{color:var(--del-text)}
.diff-stat-ins{color:var(--ins-text)}
.diff-stat-mod{color:#dcdcaa}
.diff-legend{
    display:flex;gap:12px;font-size:11px;align-items:center;
}
.diff-legend span{
    display:inline-flex;align-items:center;gap:5px;
}
.diff-legend .dot{
    width:10px;height:10px;border-radius:2px;flex-shrink:0;
}
.dot-del{background:var(--del-word)}
.dot-ins{background:var(--ins-word)}
.dot-eq {background:var(--eq-dim)}
.dot-minor{background:var(--mod-minor-bg);border:1px solid var(--mod-minor-text)}
.dot-major{background:var(--mod-major-bg)}
.dot-del-minor{background:var(--del-minor-bg);border:1px solid var(--del-minor-text)}
.dot-del-major{background:var(--del-major-bg)}
/* ── highlights toggle ── */
.toggle-switch{position:relative;display:inline-flex;align-items:center;cursor:pointer;flex-shrink:0}
.toggle-switch input{position:absolute;opacity:0;width:0;height:0}
.toggle-track{
    position:relative;
    width:35px;height:19px;
    background-color:#ccc;
    border-radius:10px;
    transition:background-color 0.3s;
    flex-shrink:0;
}
.toggle-track::after{
    content:'';position:absolute;
    top:2px;left:2px;
    width:15px;height:15px;
    background:#fff;border-radius:50%;
    transition:transform 0.3s;
}
.toggle-switch input:checked + .toggle-track{background-color:#3b50db}
.toggle-switch input:checked + .toggle-track::after{transform:translateX(16px)}
#annex-diff-root.highlights-off mark.minor,
#annex-diff-root.highlights-off mark.major,
#annex-diff-root.highlights-off mark.del-minor,
#annex-diff-root.highlights-off mark.del-major{
    background:none;color:inherit;font-weight:inherit;padding:0;border-radius:0;
}

/* ── file headers ────────────────────────────────────── */
#annex-diff-headers{
    display:grid;grid-template-columns:1fr 1fr;
    border-bottom:2px solid var(--border);
}
.diff-file-header{
    padding:10px 18px;
    display:flex;flex-direction:column;gap:3px;
}
.diff-file-header:first-child{
    border-right:1px solid var(--border);
    border-bottom:2px solid var(--accent-l);
}
.diff-file-header:last-child{
    border-bottom:2px solid var(--accent-r);
}
.diff-file-name{
    font-size:12px;font-weight:700;
    letter-spacing:.03em;
}
.diff-file-header:first-child .diff-file-name{color:var(--accent-l)}
.diff-file-header:last-child  .diff-file-name{color:var(--accent-r)}
.diff-file-desc{font-size:10px;color:var(--text-dim)}

/* ── panels wrapper (single scrollable container) ────── */
#annex-diff-body{
    height:calc(100vh - 130px);
    min-height:500px;
    overflow-y:scroll;
    overflow-x:hidden;
    background:var(--bg);
    position:relative;
}
/* ── sticky scroll (VS Code–style breadcrumb) ────────── */
#annex-diff-sticky{
    position:sticky;
    top:0;
    z-index:10;
    background:var(--surface2);
}
#annex-diff-sticky:not(:empty){
    border-bottom:1px solid var(--border);
    box-shadow:0 2px 6px rgba(0,0,0,.35);
}
.sticky-row{
    display:grid;
    grid-template-columns:1fr 1fr;
    cursor:pointer;
}
.sticky-row:hover{background:rgba(255,255,255,.06)}
.sticky-cell{
    padding:1px 18px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    font-weight:600;
    color:var(--text-hdr);
    line-height:22px;
    font-size:12px;
}
.sticky-cell-left{border-right:1px solid var(--border)}
#annex-diff-body::-webkit-scrollbar{width:8px}
#annex-diff-body::-webkit-scrollbar-track{background:var(--surface)}
#annex-diff-body::-webkit-scrollbar-thumb{
    background:var(--scroll-thumb);
    border-radius:4px;
}

/* ── 2-column grid — CSS grid equalizes row heights automatically ── */
#diff-pairs{
    display:grid;
    grid-template-columns:1fr 1fr;
}

/* ── section block inside panel ──────────────────────── */
.diff-block-left{ border-right:1px solid var(--border); }

.diff-block{
    padding:14px 20px 14px 18px;
    border-bottom:1px solid var(--border);
    min-height:40px;
    position:relative;
}
.diff-block.del-block{ background:var(--del-bg) }
.diff-block.ins-block{ background:var(--ins-bg) }
.diff-block.empty-block{
    background:repeating-linear-gradient(
        45deg,
        transparent,
        transparent 4px,
        rgba(128,128,128,.04) 4px,
        rgba(128,128,128,.04) 8px
    );
}

/* ── markdown rendering ──────────────────────────────── */
#annex-diff-root .diff-block h1{font-size:2em;font-weight:600;color:var(--text-hdr);margin:.5em 0;border-bottom:1px solid var(--border);padding-bottom:.3em}
#annex-diff-root .diff-block h2{font-size:1.5em;font-weight:600;color:var(--text-hdr);margin:.6em 0 .4em;border-bottom:1px solid var(--border);padding-bottom:.3em}
#annex-diff-root .diff-block h3{font-size:1.25em;font-weight:600;color:var(--text-hdr);margin:.6em 0 .35em}
#annex-diff-root .diff-block h4{font-size:1.1em;font-weight:600;color:var(--text-hdr);margin:.5em 0 .3em}
#annex-diff-root .diff-block h5{font-size:.875em;font-weight:600;color:var(--text-hdr);margin:.4em 0 .2em}
#annex-diff-root .diff-block h6{font-size:.85em;font-weight:600;color:var(--text-dim);margin:.4em 0 .2em}
.diff-block p{line-height:1.65;margin-bottom:.6em;color:var(--text)}
.diff-block p:last-child{margin-bottom:0}
#annex-diff-root .diff-block p.section-h1{font-size:2em;font-weight:600;color:var(--text-hdr);margin:.5em 0;border-bottom:1px solid var(--border);padding-bottom:.3em;line-height:1.2}
/* ── first-level bullets: (a), (b), (c)... ── */
#annex-diff-root .diff-block p.bullet-alpha{
    padding-left:2em;
}
/* ── second-level bullets: — dash items and (i)/(ii)/(iii) roman/sub-items ── */
#annex-diff-root .diff-block p.bullet-dash,
#annex-diff-root .diff-block p.bullet-roman{
    padding-left:4em;
    text-indent:-1.4em;
}
.diff-block ul,.diff-block ol{margin:.4em 0 .4em 1.4em;line-height:1.6}
.diff-block li{margin-bottom:.2em}
.diff-block strong{color:#fff;font-weight:700}
.diff-block em{color:#c8c8c8;font-style:italic}
.diff-block code{
    background:rgba(255,255,255,.07);
    border:1px solid var(--border);
    border-radius:3px;
    padding:1px 5px;
    font-size:.9em;
    font-family:inherit;
}
.diff-block blockquote{
    border-left:3px solid var(--border);
    padding-left:12px;
    color:var(--text-dim);
    margin:.4em 0;
}
.diff-block hr{border:none;border-top:1px solid var(--border);margin:.8em 0}
.diff-block a{color:#569cd6;text-decoration:none}

/* ── inline diff highlights — manual <mark class="del/ins/minor/major"> ── */
.diff-block mark.del{
    background:var(--del-word);
    color:var(--del-text);
    text-decoration:line-through;
    text-decoration-color:rgba(255,80,80,.6);
    border-radius:2px;
    padding:0 2px;
    font-style:normal;
}
.diff-block mark.ins{
    background:var(--ins-word);
    color:var(--ins-text);
    text-decoration:none;
    border-radius:2px;
    padding:0 2px;
    font-style:normal;
}
.diff-block mark.minor{
    background:var(--mod-minor-bg);
    color:var(--mod-minor-text);
    border-radius:2px;
    padding:0 2px;
    font-style:normal;
}
.diff-block mark.major{
    background:var(--mod-major-bg);
    color:var(--mod-major-text);
    border-radius:2px;
    padding:0 2px;
    font-style:normal;
    font-weight:600;
}
.diff-block mark.del-minor{
    background:var(--del-minor-bg);
    color:var(--del-minor-text);
    border-radius:2px;
    padding:0 2px;
    font-style:normal;
}
.diff-block mark.del-major{
    background:var(--del-major-bg);
    color:var(--del-major-text);
    border-radius:2px;
    padding:0 2px;
    font-style:normal;
    font-weight:600;
}

/* ── spacer block ─────────────────────────────────────── */
.diff-spacer{
    background:repeating-linear-gradient(
        45deg,
        transparent,transparent 3px,
        rgba(100,100,100,.05) 3px,rgba(100,100,100,.05) 6px
    );
}

/* ── status pill ─────────────────────────────────────── */
.diff-pill{
    position:absolute;
    top:10px;right:12px;
    font-size:9px;font-weight:700;letter-spacing:.08em;
    text-transform:uppercase;
    padding:2px 6px;border-radius:10px;
    pointer-events:none;
}
.pill-del{background:rgba(255,80,80,.25);color:var(--del-text);border:1px solid rgba(255,80,80,.3)}
.pill-ins{background:rgba(80,200,100,.2);color:var(--ins-text);border:1px solid rgba(80,200,100,.3)}
.pill-mod{background:rgba(220,220,170,.12);color:#dcdcaa;border:1px solid rgba(220,220,170,.2)}

/* ── loading ─────────────────────────────────────────── */
#annex-diff-loading{
    display:flex;align-items:center;justify-content:center;
    height:200px;
    color:var(--text-dim);font-size:13px;gap:10px;
}
.diff-spinner{
    width:18px;height:18px;
    border:2px solid var(--border);
    border-top-color:var(--accent-l);
    border-radius:50%;
    animation:spin .7s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── language switcher ──────────────────────────────── */
.lang-switcher{
    display:inline-flex;
    border:1px solid var(--border);
    border-radius:4px;
    overflow:hidden;
    flex-shrink:0;
}
.lang-btn{
    padding:3px 10px;
    font-size:11px;
    font-weight:600;
    letter-spacing:.05em;
    cursor:pointer;
    background:transparent;
    color:var(--text-dim);
    border:none;
    transition:background .15s,color .15s;
}
.lang-btn:hover{background:rgba(255,255,255,.06)}
.lang-btn.active{background:#3b50db;color:#fff}

/* ── Theme toggle button ── */
.theme-switcher{
    display:inline-flex;align-items:center;gap:5px;flex-shrink:0;
}
.theme-btn{
    padding:3px 8px;font-size:13px;cursor:pointer;
    background:transparent;border:1px solid var(--border);border-radius:4px;
    color:var(--text-dim);transition:background .15s,color .15s;
    line-height:1;
}
.theme-btn:hover{background:rgba(255,255,255,.06)}

/* ── Light theme ── */
#annex-diff-root.light-theme{
    --bg:       #ffffff;
    --surface:  #f5f5f7;
    --surface2: #eeeef0;
    --border:   #d0d0d5;
    --text:     #1e1e1e;
    --text-dim: #6e6e7a;
    --text-hdr: #3b50db;
    --accent-l: #2a7a65;
    --accent-r: #9e5a3c;
    --del-bg:   rgba(255,80,80,.08);
    --del-word: rgba(220,50,50,.25);
    --del-text: #c0392b;
    --ins-bg:   rgba(40,167,69,.08);
    --ins-word: rgba(40,167,69,.25);
    --ins-text: #1e8449;
    --mod-minor-bg:   rgba(40,167,69,.18);
    --mod-minor-text: #1a7a3a;
    --mod-major-bg:   rgba(0,160,60,.40);
    --mod-major-text: #0d5e25;
    --del-minor-bg:   rgba(220,50,50,.15);
    --del-minor-text: #a93226;
    --del-major-bg:   rgba(220,30,30,.40);
    --del-major-text: #7b241c;
    --eq-dim:   #d0d0d5;
    --scroll-thumb: #b0b0b8;
}
#annex-diff-root.light-theme .toggle-switch input:checked + .toggle-track{background-color:#3b50db}
#annex-diff-root.light-theme .lang-btn:hover{background:rgba(59,80,219,.08)}
#annex-diff-root.light-theme .lang-btn.active{background:#3b50db;color:#fff}
#annex-diff-root.light-theme .theme-btn:hover{background:rgba(59,80,219,.08)}
#annex-diff-root.light-theme .pill-mod{background:rgba(59,80,219,.12);color:#3b50db;border-color:rgba(59,80,219,.25)}
#annex-diff-root.light-theme .diff-block mark.minor{color:#1a7a3a}
#annex-diff-root.light-theme .diff-block mark.major{color:#0d5e25}
#annex-diff-root.light-theme .diff-block mark.del-minor{color:#a93226}
#annex-diff-root.light-theme .diff-block mark.del-major{color:#7b241c}
#annex-diff-root.light-theme #st-mod{color:#3b50db !important}
</style>

<div id="annex-diff-root">
  <!-- Toolbar -->
  <div id="annex-diff-toolbar">
    <span class="diff-toolbar-title">Porównywanie wymagań zasadniczych do maszyn</span>
    <div class="diff-stats" id="diff-stats">
      <span id="st-del" class="diff-stat-del">─</span>
      <span id="st-ins" class="diff-stat-ins">─</span>
      <span id="st-mod" style="color:#dcdcaa">─</span>
    </div>
    <div class="diff-legend">
      <span><span class="dot dot-del"></span>Usunięte</span>
      <span><span class="dot dot-ins"></span>Dodane</span>
      <span><span class="dot dot-eq"></span>Niezmienione</span>
      <span><span class="dot dot-minor"></span>Zmiana nieistotna</span>
      <span><span class="dot dot-major"></span>Zmiana istotna</span>
      <span><span class="dot dot-del-minor"></span>Usunięcie nieistotne</span>
      <span><span class="dot dot-del-major"></span>Usunięcie istotne</span>
      <label class="toggle-switch" title="Pokaż/ukryj podkreślenia zmian">
        <input type="checkbox" id="toggle-highlights" checked>
        <span class="toggle-track"></span>
      </label>
      <span style="font-size:11px;color:var(--text-dim)">Podkreślenia</span>
      <?php if ( $has_pl ) : ?>
      <div class="lang-switcher" id="lang-switcher">
        <button class="lang-btn active" data-lang="en">EN</button>
        <button class="lang-btn" data-lang="pl">PL</button>
      </div>
      <?php endif; ?>
      <div class="theme-switcher">
        <button class="theme-btn" id="theme-toggle" title="Przełącz jasny/ciemny motyw">☀️</button>
      </div>
    </div>
  </div>

  <!-- File headers -->
  <div id="annex-diff-headers">
    <div class="diff-file-header">
      <span class="diff-file-name">ANNEX I — 2006/42/WE</span>
      <span class="diff-file-desc">Dyrektywa maszynowa · Essential Health &amp; Safety Requirements</span>
    </div>
    <div class="diff-file-header">
      <span class="diff-file-name">ANNEX III — (EU) 2023/1230</span>
      <span class="diff-file-desc">Rozporządzenie maszynowe · Essential Health &amp; Safety Requirements</span>
    </div>
  </div>

  <!-- Loading -->
  <div id="annex-diff-loading">
    <div class="diff-spinner"></div>
    Generowanie porównania…
  </div>

  <!-- Panels (hidden until ready) -->
  <div id="annex-diff-body" style="display:none">
    <div id="annex-diff-sticky"></div>
    <div id="diff-pairs"></div>
  </div>
</div>

<script id="annex-diff-script">
(function(){
'use strict';

/* ══════════════════════════════════════════════════
   DATA — sections parsed from static HTML files by PHP
══════════════════════════════════════════════════ */
const LANG_DATA = {
    en: { left: <?php echo $left_js; ?>, right: <?php echo $right_js; ?> },
    pl: { left: <?php echo $left_pl_js; ?>, right: <?php echo $right_pl_js; ?> }
};
let currentLang = 'en';

/* ══════════════════════════════════════════════════
   UTILITIES
══════════════════════════════════════════════════ */
function esc(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') }

// Compare section numbers like "1.2.3" numerically
function cmpNum(a,b){
    const pa=a.split('.').map(Number), pb=b.split('.').map(Number);
    for(let i=0;i<Math.max(pa.length,pb.length);i++){
        const d=(pa[i]||0)-(pb[i]||0);
        if(d!==0) return d;
    }
    return 0;
}


/* ══════════════════════════════════════════════════
   SECTION MATCHER
   Returns array of { left, right } pairs
══════════════════════════════════════════════════ */
function matchSections(L, R){
    const pairs = [];

    // Preamble pair
    if((L.preamble_html||'').trim() || (R.preamble_html||'').trim()){
        pairs.push({
            left:  { type:'preamble', html: L.preamble_html||'' },
            right: { type:'preamble', html: R.preamble_html||'' }
        });
    }

    const lByNum={}, rByNum={};
    const lUnn=[], rUnn=[];

    (L.sections||[]).forEach(s=>{ s.number ? (lByNum[s.number]=s) : lUnn.push(s) });
    (R.sections||[]).forEach(s=>{ s.number ? (rByNum[s.number]=s) : rUnn.push(s) });

    const unnMax = Math.max(lUnn.length, rUnn.length);
    for(let i=0;i<unnMax;i++){
        pairs.push({ left: lUnn[i]||null, right: rUnn[i]||null });
    }

    const allNums = new Set([...Object.keys(lByNum), ...Object.keys(rByNum)]);
    const sorted  = [...allNums].sort(cmpNum);
    for(const num of sorted){
        pairs.push({ left: lByNum[num]||null, right: rByNum[num]||null });
    }

    return pairs;
}

/* ══════════════════════════════════════════════════
   BUILD DOM
══════════════════════════════════════════════════ */
let stats = { del:0, ins:0, mod:0 };

/* Split an HTML string into an array of top-level DOM elements */
function splitIntoBlocks(html){
    if(!html||!html.trim()) return [];
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    return Array.from(tmp.children);
}

/* Does this HTML contain manual diff marks? */
function hasMarks(html){ return html.includes('<mark'); }

function buildPair(pair, container){
    const { left, right } = pair;

    if(!left && right){
        // Pure addition — single block
        stats.ins++;
        const lB = document.createElement('div');
        const rB = document.createElement('div');
        lB.className = 'diff-block diff-block-left empty-block diff-spacer';
        rB.className = 'diff-block ins-block';
        rB.innerHTML = (right.heading_html||'') + (right.html||'');
        const p = document.createElement('span');
        p.className='diff-pill pill-ins'; p.textContent='Dodane';
        rB.appendChild(p);
        container.appendChild(lB);
        container.appendChild(rB);
        return;
    }

    if(left && !right){
        // Pure deletion — single block
        stats.del++;
        const lB = document.createElement('div');
        const rB = document.createElement('div');
        lB.className = 'diff-block diff-block-left del-block';
        rB.className = 'diff-block empty-block diff-spacer';
        lB.innerHTML = (left.heading_html||'') + (left.html||'');
        const p = document.createElement('span');
        p.className='diff-pill pill-del'; p.textContent='Usunięte';
        lB.appendChild(p);
        container.appendChild(lB);
        container.appendChild(rB);
        return;
    }

    // Matched pair — differences pre-marked with <mark class="del/ins"> in HTML
    const lHeadHtml = left.heading_html  || '';
    const rHeadHtml = right.heading_html || '';
    const lBody     = left.html          || '';
    const rBody     = right.html         || '';
    const modified  = hasMarks(lHeadHtml+lBody) || hasMarks(rHeadHtml+rBody);

    // — Row 1: heading (only when section has a heading, not preamble) —
    if(lHeadHtml || rHeadHtml){
        const lHB = document.createElement('div');
        const rHB = document.createElement('div');
        lHB.className = 'diff-block diff-block-left';
        rHB.className = 'diff-block';
        lHB.innerHTML = lHeadHtml;
        rHB.innerHTML = rHeadHtml;
        if(modified){
            stats.mod++;
            const pl = document.createElement('span');
            pl.className='diff-pill pill-mod'; pl.textContent='Zmienione';
            lHB.appendChild(pl);
            const pr = document.createElement('span');
            pr.className='diff-pill pill-mod'; pr.textContent='Zmienione';
            rHB.appendChild(pr);
        }
        container.appendChild(lHB);
        container.appendChild(rHB);
    }

    // — Rows 2…N: sequential block pairs (matched by position) —
    const lBlocks = splitIntoBlocks(lBody);
    const rBlocks = splitIntoBlocks(rBody);
    const n = Math.max(lBlocks.length, rBlocks.length);
    for(let i=0; i<n; i++){
        const lB = document.createElement('div');
        const rB = document.createElement('div');
        lB.className = 'diff-block diff-block-left';
        rB.className = 'diff-block';
        const lHas = lBlocks[i] && lBlocks[i].textContent.trim();
        const rHas = rBlocks[i] && rBlocks[i].textContent.trim();
        if(lBlocks[i]) lB.appendChild(lBlocks[i]);
        if(!lHas)      lB.classList.add('empty-block');
        if(rBlocks[i]){
            rB.appendChild(rBlocks[i]);
            if(!lHas) rB.classList.add('ins-block');
        } else {
            rB.classList.add('empty-block');
        }
        container.appendChild(lB);
        container.appendChild(rB);
    }
}


/* ══════════════════════════════════════════════════
   MAIN — build all pairs for a given language
══════════════════════════════════════════════════ */
const bodyEl      = document.getElementById('annex-diff-body');
const containerEl = document.getElementById('diff-pairs');
const stickyEl    = document.getElementById('annex-diff-sticky');

function buildAll(lang){
    containerEl.innerHTML = '';
    stats = { del:0, ins:0, mod:0 };

    const data  = LANG_DATA[lang];
    if(!data || !data.left) return;
    const pairs = matchSections(data.left, data.right);
    for(const pair of pairs) buildPair(pair, containerEl);

    document.getElementById('st-del').textContent = `${stats.del} sekcji usuniętych`;
    document.getElementById('st-ins').textContent = `${stats.ins} sekcji dodanych`;
    document.getElementById('st-mod').textContent = `${stats.mod} sekcji zmienionych`;
}

// Initial build
buildAll('en');
document.getElementById('annex-diff-loading').style.display = 'none';
bodyEl.style.display = 'block';

document.getElementById('toggle-highlights').addEventListener('change', function(){
    document.getElementById('annex-diff-root').classList.toggle('highlights-off', !this.checked);
});

/* ══════════════════════════════════════════════════
   THEME TOGGLE — light / dark
══════════════════════════════════════════════════ */
(function(){
    const root = document.getElementById('annex-diff-root');
    const btn  = document.getElementById('theme-toggle');
    const KEY  = 'annex_diff_theme';
    function applyTheme(light){
        root.classList.toggle('light-theme', light);
        btn.textContent = light ? '🌙' : '☀️';
        btn.title = light ? 'Przełącz na ciemny motyw' : 'Przełącz na jasny motyw';
    }
    // Restore saved preference
    applyTheme(localStorage.getItem(KEY) === 'light');
    btn.addEventListener('click', function(){
        const goLight = !root.classList.contains('light-theme');
        applyTheme(goLight);
        localStorage.setItem(KEY, goLight ? 'light' : 'dark');
    });
})();

/* ══════════════════════════════════════════════════
   STICKY SCROLL — VS Code–style breadcrumb headers
══════════════════════════════════════════════════ */
let headingRows = [];
let stickyLastKey = '';
const ROW_H = 22;

function collectHeadings(){
    headingRows = [];
    const blocks = containerEl.children;
    for(let i=0; i<blocks.length-1; i+=2){
        const lBlock = blocks[i], rBlock = blocks[i+1];
        const lh = lBlock.querySelector('h1,h2,h3,h4,h5,h6');
        const rh = rBlock.querySelector('h1,h2,h3,h4,h5,h6');
        if(lh||rh){
            headingRows.push({
                level: parseInt((lh||rh).tagName[1]),
                leftText:  lh ? lh.textContent.trim() : '',
                rightText: rh ? rh.textContent.trim() : '',
                el: lBlock
            });
        }
    }
}

function cachePositions(){
    const bodyRect = bodyEl.getBoundingClientRect();
    const st = bodyEl.scrollTop;
    for(const hr of headingRows){
        hr.top = hr.el.getBoundingClientRect().top - bodyRect.top + st;
    }
}

function updateSticky(){
    const scrollTop = bodyEl.scrollTop;
    const stack = [];

    for(const hr of headingRows){
        const stickyH = stack.length * ROW_H;
        if(hr.top > scrollTop + stickyH) break;
        while(stack.length && stack[stack.length-1].level >= hr.level) stack.pop();
        stack.push(hr);
    }

    const key = stack.map(h=>h.level+'_'+h.leftText).join('|');
    if(key === stickyLastKey) return;
    stickyLastKey = key;

    if(!stack.length){ stickyEl.innerHTML=''; return; }

    let html='';
    for(const hr of stack){
        const indent = (hr.level-1)*16;
        const idx = headingRows.indexOf(hr);
        html += '<div class="sticky-row" data-idx="'+idx+'">'
            + '<div class="sticky-cell sticky-cell-left" style="padding-left:'+(18+indent)+'px">'+esc(hr.leftText)+'</div>'
            + '<div class="sticky-cell" style="padding-left:'+(18+indent)+'px">'+esc(hr.rightText)+'</div>'
            + '</div>';
    }
    stickyEl.innerHTML = html;
}

function initSticky(){
    collectHeadings();
    cachePositions();
    stickyLastKey = '';
    updateSticky();
}

bodyEl.addEventListener('scroll', updateSticky, {passive:true});
window.addEventListener('resize', function(){ cachePositions(); });

stickyEl.addEventListener('click', function(e){
    const row = e.target.closest('.sticky-row');
    if(!row) return;
    const hr = headingRows[parseInt(row.dataset.idx)];
    if(!hr) return;
    bodyEl.scrollTop = hr.top - stickyEl.offsetHeight;
});

// Init sticky for first build
initSticky();

/* ══════════════════════════════════════════════════
   LANGUAGE SWITCHING
══════════════════════════════════════════════════ */
function getCurrentBlockIndex(){
    const blocks = containerEl.children;
    const stickyH = stickyEl.offsetHeight || 0;
    const threshold = bodyEl.getBoundingClientRect().top + stickyH;
    for(let i=0; i<blocks.length; i++){
        const rect = blocks[i].getBoundingClientRect();
        if(rect.bottom > threshold){
            return { index: i, offset: threshold - rect.top };
        }
    }
    return { index: 0, offset: 0 };
}

function switchLanguage(lang){
    if(lang === currentLang) return;
    if(!LANG_DATA[lang] || !LANG_DATA[lang].left) return;

    // Remember position
    const pos = getCurrentBlockIndex();

    currentLang = lang;
    buildAll(lang);
    initSticky();

    // Restore position
    const blocks = containerEl.children;
    if(blocks[pos.index]){
        const stickyH = stickyEl.offsetHeight || 0;
        bodyEl.scrollTop = blocks[pos.index].offsetTop - stickyH + pos.offset;
    }

    // Update button states
    document.querySelectorAll('.lang-btn').forEach(function(btn){
        btn.classList.toggle('active', btn.dataset.lang === lang);
    });

    // Update file headers for language
    const headers = document.querySelectorAll('#annex-diff-headers .diff-file-header');
    if(lang === 'pl'){
        headers[0].querySelector('.diff-file-name').textContent = 'Załącznik I — 2006/42/WE';
        headers[0].querySelector('.diff-file-desc').textContent = 'Dyrektywa maszynowa · Wymagania zasadnicze';
        headers[1].querySelector('.diff-file-name').textContent = 'Załącznik III — (EU) 2023/1230';
        headers[1].querySelector('.diff-file-desc').textContent = 'Rozporządzenie maszynowe · Wymagania zasadnicze';
    } else {
        headers[0].querySelector('.diff-file-name').textContent = 'ANNEX I — 2006/42/WE';
        headers[0].querySelector('.diff-file-desc').textContent = 'Dyrektywa maszynowa · Essential Health & Safety Requirements';
        headers[1].querySelector('.diff-file-name').textContent = 'ANNEX III — (EU) 2023/1230';
        headers[1].querySelector('.diff-file-desc').textContent = 'Rozporządzenie maszynowe · Essential Health & Safety Requirements';
    }
}

const switcher = document.getElementById('lang-switcher');
if(switcher){
    switcher.addEventListener('click', function(e){
        const btn = e.target.closest('.lang-btn');
        if(btn) switchLanguage(btn.dataset.lang);
    });
}

})();
</script>
<?php
    return ob_get_clean();
}

/* ──────────────────────────────────────────────
   Optional: full-width page template support
   Add class "annex-diff-fullwidth" to body via filter
   ────────────────────────────────────────────── */
add_filter('body_class','annex_diff_body_class');
function annex_diff_body_class($classes){
    global $post;
    if(is_a($post,'WP_Post') && has_shortcode($post->post_content,'annex_diff')){
        $classes[]='annex-diff-page';
    }
    return $classes;
}

// Minimal CSS to help most themes go full-width on this page
add_action('wp_head','annex_diff_page_styles');
function annex_diff_page_styles(){
    global $post;
    if(!is_a($post,'WP_Post') || !has_shortcode($post->post_content,'annex_diff')) return;
    echo '<style>
    body.annex-diff-page .entry-content,
    body.annex-diff-page .site-main,
    body.annex-diff-page article,
    body.annex-diff-page .post-content,
    body.annex-diff-page .wp-block-group,
    body.annex-diff-page .content-area{
        max-width:100%!important;
        width:100%!important;
        padding:0!important;
        margin:0!important;
    }
    body.annex-diff-page .site-content,
    body.annex-diff-page #primary{
        max-width:100%!important;
        width:100%!important;
    }
    body.annex-diff-page #annex-diff-root{
        width:100vw;
        margin-left:calc(-50vw + 50%);
    }
    </style>';
}
