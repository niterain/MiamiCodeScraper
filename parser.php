<?php

require_once('phpquery/phpQuery/phpQuery.php');


class Parser {
    protected $url = 'http://library.municode.com/toc.aspx?clientId=10620&checks=false';
    protected $content = array();
    
    function getLinks() { 
        $links = array();
        $base_url = substr($this->url, 0, strrpos($this->url, '/')).'/';
        try {
            phpQuery::newDocument($this->getContent($this->url));
            foreach (pq('a') as $a) {
                $links[] = $base_url.pq($a)->attr('href');
            }
        } catch (\Exception $e) {
            print "*E* {$e->getMessage()}\n";
        }
        print "Finished gathering links\n";
        return $links;
    }

    function getContent($link) {
        phpQuery::ajaxAllowURL($link);
        $response = phpQuery::get($link);
        return $response->getLastResponse()->getBody();
    }

    function parse($content) {
        phpQuery::newDocument($content);
        $full_title = pq('h2')->html();
        if (preg_match('/\n/', $full_title)) {
            list($type, $title) = split("\n", $full_title);
        } else {
            $type = 'unknown';
            $title = $full_title;
        }
        $title = trim(strip_tags($title));
        
        if (!empty($type) && !empty($title)) {
            $this->content['title'] = $title;
            $this->content['type'] = $type;
            foreach (pq('span') as $section_index => $section) {
                foreach(pq('p.sec',$section) as $p) {
                    list($section_id, $section) = split("\n", pq($p)->text());
                    $section_id = trim($section_id);
                    $section = trim($section);
                    $section_id = preg_replace('~\.$~','', $section_id);
                    $section_id = preg_replace('~[^0-9\-\.]~', '', $section_id);
                    $section_id = preg_replace('~^\.~','', $section_id);
                    $this->content['sections'][$section_index]['section_id'] = $section_id;
                    $this->content['sections'][$section_index]['section'] = $section;
                    foreach(pq("p[class^='p']", pq($p)->parent('span')) as $index => $part) {
                        $this->content['sections'][$section_index]['intro'][$index]['description'] = trim(preg_replace('~\s+~',' ',preg_replace('~\n~', ' ', pq($part)->text())));
                    }
                    foreach(pq("p[class^='incr']", pq($p)->parent('span')) as $index => $part) {
                        $this->content['sections'][$section_index]['item'][$index]['label'] = preg_replace('~[\(\)\.]~','',pq($part)->text());
                    }
                    foreach(pq("p[class^='content']", pq($p)->parent('span')) as $index => $part) {
                        $this->content['sections'][$section_index]['item'][$index]['details'] = trim(preg_replace('~\s+~',' ',preg_replace('~\n~', ' ', pq($part)->text())));
                    }
               }
            }
        }
        return $this->content;
    }

    function build($data) {
        if (!empty($data['sections'])) {
            foreach($data['sections'] as $index => $section) {
                $section_id = $section['section_id'];
                $filename = strtolower(preg_replace('~[^A-Za-z0-9]~','-', $data['title']).'-'.preg_replace('~[^0-9]~','',strtolower($section['section_id'])));
                $filename = (strlen($filename) > 200) ? strtolower(md5($filename)) : $filename;
                $fp = fopen('xml/'.$filename.'.xml', 'w');
               
                $type = $section['section_id'];
                $type_id = preg_replace('~[^0-9\-\.]~','', strtolower($data['type']));

                $catch_line = '';
                if (!empty($section['intro']) && count($section['intro'])>0) {
                    foreach($section['intro'] as $index => $item) {
                        $catch_line .= $item['description']."\n\n";
                    }
                }

                $xml =<<<XML
<?xml version="1.0" encoding="utf-8"?>
<law>
    <structure>
        <unit label="title" identifier="{$section['section_id']}" order_by="{$section['section_id']}" level="1">{$section['section']}</unit>
    </structure>
    <section_number>$type_id</section_number>
    <catch_line>$catch_line</catch_line>
    <order_by>000000$section_id</order_by>
    <text>

XML;
                if (!empty($section['item'])) {
                    foreach ($section['item'] as $index => $part) {
                        if (!empty($part['details'])) {
                            $part['details'] = str_replace('&', '&#038;', $part['details']);
                            $xml .=<<<XML
        <section prefix="{$part['label']}">
            {$part['details']}
        </section>

XML;
                        }
                    }
                }

                $xml .=<<<XML
    </text>
</law>
XML;
                fwrite($fp, $xml);
                fclose($fp);
            }
        }
    }

    function process() {
        foreach ($this->getLinks() as $link) {
            print_r($this->build($this->parse($this->getContent($link))));
            //print_r($this->parse($this->getContent($link)));
        }
    }
}

$xml = new Parser();
$xml->process();
