<?php

declare(strict_types=1);

/**
 * 富文本 HTML 清洗。
 *
 * 允许常见排版、图片、表格、音视频标签；去掉脚本、事件属性、伪协议 URL、
 * SVG / MathML / 表单等可执行或可钓鱼的内容。用于商户公告、商品详情、
 * 页面、文章等富文本输出。
 */
final class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'a', 'b', 'strong', 'i', 'em', 'u', 's', 'del', 'ins', 'p', 'br', 'hr',
        'ul', 'ol', 'li', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'img', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tfoot',
        'tr', 'th', 'td', 'code', 'pre', 'span', 'div', 'video', 'audio',
        'source', 'iframe',
    ];

    /** 这些标签连同内容一起删除 */
    private const DROP_TAGS = [
        'script', 'style', 'link', 'meta', 'base', 'title', 'head', 'html',
        'body', 'form', 'input', 'button', 'select', 'textarea', 'option',
        'optgroup', 'label', 'object', 'embed', 'applet', 'svg', 'math',
        'template', 'frame', 'frameset', 'noframes',
    ];

    private const ALLOWED_ATTRS = [
        'href', 'src', 'alt', 'title', 'width', 'height', 'class', 'style',
        'target', 'rel', 'colspan', 'rowspan', 'poster', 'controls', 'allow',
        'allowfullscreen', 'frameborder', 'loading', 'type', 'start', 'value',
    ];

    /** @var array<string, string> 同一请求内相同内容只清洗一次 */
    private static $cache = [];

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        // 纯文本直接转义，省掉 DOM 解析
        if (strpos($html, '<') === false) {
            return htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
        }

        $cacheKey = md5($html);
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="__em_sanitize_root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            return self::$cache[$cacheKey] = '';
        }

        $root = $doc->getElementById('__em_sanitize_root');
        if ($root === null) {
            return self::$cache[$cacheKey] = '';
        }

        self::cleanNode($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= (string) $doc->saveHTML($child);
        }

        return self::$cache[$cacheKey] = $out;
    }

    private static function cleanNode(DOMNode $node): void
    {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);
            if ($child === null) {
                continue;
            }

            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!($child instanceof DOMElement)) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP_TAGS, true)) {
                $node->removeChild($child);
                continue;
            }
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                // 未知标签：保留文字内容，去掉标签本身
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            self::cleanAttributes($child, $tag);
            self::cleanNode($child);
        }
    }

    private static function cleanAttributes(DOMElement $el, string $tag): void
    {
        for ($i = $el->attributes->length - 1; $i >= 0; $i--) {
            $attr = $el->attributes->item($i);
            if (!($attr instanceof DOMAttr)) {
                continue;
            }

            $name = strtolower($attr->nodeName);
            if (strpos($name, 'on') === 0 || !in_array($name, self::ALLOWED_ATTRS, true)) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }

            if (in_array($name, ['href', 'src', 'poster'], true)) {
                if (!self::isSafeUrl((string) $attr->nodeValue, $tag, $name)) {
                    $el->removeAttribute($attr->nodeName);
                }
            }
        }

        if ($tag === 'a' && strtolower((string) $el->getAttribute('target')) === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function isSafeUrl(string $url, string $tag, string $attr): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        // 浏览器会忽略 URL 里的控制字符，先挡掉再判断协议
        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return false;
        }

        // 去掉空白后再判断伪协议，防止 java\nscript: 之类的变形
        $probe = strtolower(preg_replace('/[\x00-\x20]+/', '', substr($url, 0, 32)) ?? '');
        if (preg_match('#^(javascript|data|vbscript|file):#', $probe)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme === '') {
            // 相对路径 / 锚点 / 协议相对地址
            return true;
        }
        if ($scheme === 'http' || $scheme === 'https') {
            return true;
        }
        if (($scheme === 'mailto' || $scheme === 'tel') && $tag === 'a' && $attr === 'href') {
            return true;
        }

        return false;
    }
}
