<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.xmlsitemap
 *
 * @copyright   Copyright (C) 2024 Bionic Laboratories BLG GmbH
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

/**
 * XML Sitemap Generator Plugin - Version 3.3
 * Baut SEF URLs direkt aus der Menüstruktur
 * 
 * WICHTIG: Nutzt onAfterInitialise um VOR dem Joomla-Routing einzugreifen
 *
 * @since  1.0.0
 */
class PlgSystemXmlsitemap extends CMSPlugin
{
    protected $app;
    protected $db;
    protected $autoloadLanguage = true;
    
    // Cache für Menüdaten
    protected $menuItems = [];
    protected $menuPaths = [];
    protected $categoryMenuMap = [];
    
    // URLs/Aliase die ausgeschlossen werden sollen
    protected $excludedAliases = [
        'root',
        'all-languages',
        'all-language',
    ];

    /**
     * onAfterInitialise event handler - wird VOR dem Routing ausgeführt
     * Das ist wichtig, damit /sitemap.xml abgefangen wird bevor Joomla 404 wirft
     */
    public function onAfterInitialise()
    {
        // Nur im Frontend
        if (!$this->app->isClient('site')) {
            return;
        }
        
        // Request URI prüfen
        $uri = Uri::getInstance();
        $path = $uri->getPath();
        
        // Verschiedene Pfad-Varianten prüfen
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        
        // Debug: Uncomment to see what's being requested
        // file_put_contents(JPATH_ROOT . '/sitemap_debug.log', date('Y-m-d H:i:s') . " Path: $path | URI: $requestUri\n", FILE_APPEND);
        
        // Prüfe ob sitemap.xml angefragt wird
        if (preg_match('/sitemap\.xml$/i', $path) || 
            preg_match('/sitemap\.xml$/i', $requestUri) ||
            preg_match('/\/sitemap\.xml/i', $requestUri)) {
            $this->generateSitemap();
            return;
        }
    }

    /**
     * onAfterRoute event handler - Fallback für com_ajax Aufrufe
     */
    public function onAfterRoute()
    {
        // Nur im Frontend
        if (!$this->app->isClient('site')) {
            return;
        }
        
        $input = $this->app->input;
        
        // Check if sitemap via com_ajax is requested
        if ($input->get('option') === 'com_ajax' 
            && $input->get('plugin') === 'xmlsitemap' 
            && $input->get('group') === 'system') {
            $this->generateSitemap();
        }
    }

    /**
     * Lädt alle Menüdaten und baut Pfad-Cache
     */
    protected function loadMenuData()
    {
        $db = $this->db;
        
        // Alle veröffentlichten Menüeinträge laden
        $query = $db->getQuery(true)
            ->select(['m.id', 'm.alias', 'm.path', 'm.link', 'm.type', 'm.parent_id', 
                      'm.level', 'm.language', 'm.menutype'])
            ->from($db->quoteName('#__menu', 'm'))
            ->where($db->quoteName('m.published') . ' = 1')
            ->where($db->quoteName('m.client_id') . ' = 0')
            ->order($db->quoteName('m.lft'));
        
        $db->setQuery($query);
        $items = $db->loadObjectList('id');
        
        $this->menuItems = $items;
        
        // Pfade und Kategorie-Mapping bauen
        foreach ($items as $item) {
            // Sprach-Prefix ermitteln
            $langPrefix = '';
            if ($item->language && $item->language !== '*') {
                $langPrefix = substr($item->language, 0, 2) . '/';
            }
            
            // Pfad ist bereits in Joomla gespeichert
            if (!empty($item->path)) {
                $this->menuPaths[$item->id] = $langPrefix . $item->path;
            } else {
                $this->menuPaths[$item->id] = $langPrefix . $item->alias;
            }
            
            // Kategorie-Blog Einträge für Artikel-Mapping
            if (strpos($item->link, 'view=category') !== false && 
                strpos($item->link, 'layout=blog') !== false) {
                // Kategorie-ID aus Link extrahieren
                if (preg_match('/id=(\d+)/', $item->link, $matches)) {
                    $catId = $matches[1];
                    $this->categoryMenuMap[$catId] = $item->id;
                }
            }
        }
    }

    /**
     * Generate and output the XML sitemap
     */
    protected function generateSitemap()
    {
        // Header setzen
        header('Content-Type: application/xml; charset=utf-8');
        header('X-Robots-Tag: noindex');
        
        // Menüdaten laden
        $this->loadMenuData();
        
        $urls = [];
        
        // Homepage
        $urls[] = [
            'loc' => rtrim(Uri::root(), '/') . '/',
            'changefreq' => 'daily',
            'priority' => '1.0',
            'lastmod' => date('c')
        ];
        
        // Menüeinträge
        if ($this->params->get('include_menu', 1)) {
            $urls = array_merge($urls, $this->getMenuUrls());
        }
        
        // Artikel (nur mit schönen URLs)
        if ($this->params->get('include_articles', 1)) {
            $urls = array_merge($urls, $this->getArticleUrls());
        }
        
        // Duplikate entfernen
        $urls = $this->removeDuplicates($urls);
        
        echo $this->generateXML($urls);
        
        // Beende die Anwendung komplett
        jexit();
    }

    /**
     * Menü-URLs generieren
     */
    protected function getMenuUrls()
    {
        $urls = [];
        $baseUrl = rtrim(Uri::root(), '/');
        
        foreach ($this->menuItems as $item) {
            // Überspringe spezielle Typen
            if (in_array($item->type, ['separator', 'heading', 'url', 'alias'])) {
                continue;
            }
            
            // Externe Links überspringen
            if (strpos($item->link, 'http') === 0) {
                continue;
            }
            
            // System-Menüeinträge überspringen (root, com_users, etc.)
            if ($item->alias === 'root' || empty($item->alias)) {
                continue;
            }
            
            // Ausgeschlossene Aliase überspringen
            if (in_array($item->alias, $this->excludedAliases)) {
                continue;
            }
            
            // Pfad auf ausgeschlossene Begriffe prüfen
            $skipItem = false;
            foreach ($this->excludedAliases as $excluded) {
                if (strpos($item->path ?? '', $excluded) !== false) {
                    $skipItem = true;
                    break;
                }
            }
            if ($skipItem) {
                continue;
            }
            
            // Joomla System-Links überspringen
            if (strpos($item->link, 'com_users') !== false) {
                continue;
            }
            
            $path = $this->menuPaths[$item->id] ?? $item->alias;
            
            // Leere Pfade oder "root" überspringen
            if (empty($path) || $path === 'root' || $path === '/') {
                continue;
            }
            
            $url = $baseUrl . '/' . $path;
            
            $urls[] = [
                'loc' => $url,
                'changefreq' => 'weekly',
                'priority' => '0.8',
                'lastmod' => date('c')
            ];
        }
        
        return $urls;
    }

    /**
     * Artikel-URLs generieren - nur für Artikel mit Kategorie-Menüeintrag
     */
    protected function getArticleUrls()
    {
        $urls = [];
        $baseUrl = rtrim(Uri::root(), '/');
        $db = $this->db;
        
        // Artikel mit Kategorien laden
        $query = $db->getQuery(true)
            ->select(['a.id', 'a.alias', 'a.catid', 'a.modified', 'a.created', 'a.language',
                      'c.alias as cat_alias', 'c.path as cat_path'])
            ->from($db->quoteName('#__content', 'a'))
            ->join('LEFT', $db->quoteName('#__categories', 'c') . ' ON c.id = a.catid')
            ->where($db->quoteName('a.state') . ' = 1')
            ->order($db->quoteName('a.created') . ' DESC');
        
        $db->setQuery($query);
        $articles = $db->loadObjectList();
        
        foreach ($articles as $article) {
            $url = null;
            
            // Hat diese Kategorie einen Menüeintrag?
            if (isset($this->categoryMenuMap[$article->catid])) {
                $menuId = $this->categoryMenuMap[$article->catid];
                $menuPath = $this->menuPaths[$menuId] ?? '';
                
                if ($menuPath) {
                    // Schöne URL: /de/investor-relations/artikel-alias
                    $url = $baseUrl . '/' . $menuPath . '/' . $article->alias;
                }
            }
            
            // Fallback: Suche nach Einzelartikel-Menüeintrag
            if (!$url) {
                foreach ($this->menuItems as $menuItem) {
                    if (strpos($menuItem->link, 'view=article') !== false &&
                        strpos($menuItem->link, 'id=' . $article->id) !== false) {
                        $url = $baseUrl . '/' . ($this->menuPaths[$menuItem->id] ?? $menuItem->alias);
                        break;
                    }
                }
            }
            
            // Nur hinzufügen wenn schöne URL gefunden
            if ($url) {
                $urls[] = [
                    'loc' => $url,
                    'changefreq' => 'weekly',
                    'priority' => '0.6',
                    'lastmod' => $article->modified ? date('c', strtotime($article->modified)) : date('c', strtotime($article->created))
                ];
            }
        }
        
        return $urls;
    }

    /**
     * Duplikate aus URL-Array entfernen
     */
    protected function removeDuplicates($urls)
    {
        $seen = [];
        $unique = [];
        
        foreach ($urls as $url) {
            $loc = rtrim($url['loc'], '/');
            if (!isset($seen[$loc])) {
                $seen[$loc] = true;
                $unique[] = $url;
            }
        }
        
        return $unique;
    }

    /**
     * Generate XML from URLs array
     */
    protected function generateXML($urls)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        foreach ($urls as $url) {
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . htmlspecialchars($url['loc']) . '</loc>' . "\n";
            
            if (isset($url['lastmod'])) {
                $xml .= '    <lastmod>' . $url['lastmod'] . '</lastmod>' . "\n";
            }
            
            if (isset($url['changefreq'])) {
                $xml .= '    <changefreq>' . $url['changefreq'] . '</changefreq>' . "\n";
            }
            
            if (isset($url['priority'])) {
                $xml .= '    <priority>' . $url['priority'] . '</priority>' . "\n";
            }
            
            $xml .= '  </url>' . "\n";
        }
        
        $xml .= '</urlset>';
        
        return $xml;
    }
}
