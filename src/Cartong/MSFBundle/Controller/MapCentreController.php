<?php

namespace Cartong\MSFBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Cartong\MSFBundle\Geonetwork\Client;

use Guzzle\Http\Exception\BadResponseException;
use Symfony\Component\DomCrawler\Crawler;

use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Request;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Security\Core\SecurityContext;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use Symfony\Component\CssSelector\CssSelector;

class MapCentreController extends Controller
{

    protected $emergenciesFile = '../emergencies.json';
    protected $itemsPerResultPage = 20;

    /**
     * This method makes a query to the Geonetwork server.
     * @param string $query the query to execute (i.e. the URL).
     * @param boolean $returnString if true returns the response otherwise the response's body.
     */
    protected function makeQuery($query, $returnString = true, $requestMethod = 'get')
    {
        $user = $this->getUser();
        $jsessionID = $user->getJSESSIONID();

        try
        {
            $client = $this->get('geonetwork_client');
            if ($requestMethod==='get')
            {
                $response = $client->get($query, $jsessionID);
            }
            elseif ($requestMethod==='head')
            {
                $response = $client->head($query, $jsessionID);
            }
            if ($response->getStatusCode()===302)
            {
                throw new AccessDeniedException();
            }
            $body = $response->getBody();

            if ($returnString)
            {
                return (string)$body;
            }
            else
            {
                return $response;
            }
        }
        catch (AccessDeniedException $e)
        {
            $this->get('security.context')->setToken(null);
            $this->get('request')->getSession()->invalidate();
            throw $e;
        }
    }

    /**
     * Remap URLs found in a string to local server.
     */
    protected function remapUrls($content)
    {
        $resourceUrl = $this->generateUrl('cartong_msf_mapcentre_resource', [], true);
        $content = str_replace('http://msfmapcentre.cartong.org:80/geonetwork/srv/en/resources.get', $resourceUrl, $content);
        $content = str_replace('http://msfmapcentre.cartong.org:80/geonetwork/srv/eng/resources.get', $resourceUrl, $content);
        $content = str_replace('http://msfmapcentre.cartong.org/geonetwork/srv/en/resources.get', $resourceUrl, $content);
        $content = str_replace('http://msfmapcentre.cartong.org/geonetwork/srv/eng/resources.get', $resourceUrl, $content);
        return $content;
    }
    
    /**
     * @Route("/login")
     * @Template
     * @Method("GET")
     */
    public function loginAction(Request $request)
    {
        $session = $request->getSession();
        if ($request->attributes->has(SecurityContext::AUTHENTICATION_ERROR))
        {
            $error = $request->attributes->get(SecurityContext::AUTHENTICATION_ERROR);
        }
        else
        {
            $error = $session->get(SecurityContext::AUTHENTICATION_ERROR);
            $session->remove(SecurityContext::AUTHENTICATION_ERROR);
        }
        return [
            'error' => $error,
        ];
    }

    /**
     * @Route("/login_check")
     * @Method("POST")
     */
    public function postLoginAction(Request $request)
    {
        // Nothing to implement because Symfony2 will intercept the request
    }

    /**
     * @Route("/logout")
     * @Security("has_role('ROLE_USER')")
     */
    public function logoutAction()
    {
        // Nothing to implement because Symfony2 will intercept the request
        // @todo Find a way to listen to logout action and logout on the Geonetwork side.
        // i.e. call the following url: $url = $this->geonetworkUrl.'j_spring_security_logout';
    }

    /**
     * @Route("/")
     * @Template
     * @Security("has_role('ROLE_USER')")
     */
    public function indexAction()
    {
        // Query the 4 latest metadata
        $body = $this->makeQuery('srv/eng/search/q?fast=index&from=1&to=4&sortBy=changeDate');

        $crawler = new Crawler((string)$body);

        $maps = [];
        $metadataResults = $crawler->filterXPath('//metadata');
        foreach ($metadataResults as $domElement) {
            $metadata = new Crawler($domElement);

            $map = [];
            $map['title'] = $metadata->filterXPath('//title')->text();
            $map['uuid'] = $metadata->filterXPath('//geonet:info/uuid')->text();
            $map['abstract'] = $metadata->filterXPath('//abstract')->text();
            if ($metadata->filterXPath('//image')->count())
            {
                $image = $metadata->filterXPath('//image')->text();
                $image = explode('|', $image);
                $map['image'] = $this->remapUrls($image[1]).'&width=260&height=130';
            }
            $maps []= $map;
        }

        // Query emergencies.
        $emergencies = [];
        if (file_exists($this->emergenciesFile))
        {
            $emergencies = json_decode(file_get_contents($this->emergenciesFile));
            $emergencies = array_map(function($emergency) {
                return $emergency->country;
            }, $emergencies);
        }
        
        // @todo The search options operate with AND and not OR... see how we can by pass this behaviour.
        //$showAllUri = array_map(function($emergency) { return 'geoDescCode[]='.$emergency; }, $emergencies);
        //$showAllUri = $this->generateUrl('cartong_msf_mapcentre_search').'?'.implode('&', $showAllUri);
        $showAllUri = '#';

        return [
            'recentMaps' => $maps,
            'emergencies' => $emergencies,
            'showAllUri' => $showAllUri,
        ];
    }

    /**
     * @Route("/search")
     * @Template
     * @Security("has_role('ROLE_USER')")
     */
    public function searchAction(Request $request)
    {
        $from = $request->query->get('from', 1);
        $to = $request->query->get('to', $this->itemsPerResultPage);
        
        // Build the clearFilterUrl
        $clearFilterUrl = $this->generateUrl('cartong_msf_mapcentre_search');

        // Build the baseUri
        $get = $request->query->all();
        $get['from'] = $from;
        $get['to'] = $to;
        $baseUri = '?'.http_build_query($get);

        // Geonetwork supports multiple query string with same name, PHP doesn't so remove the array syntax.
        $query = preg_replace('/%5B[0-9]*%5D/', '', http_build_query($get));
        if (!isset($get['sortBy']))
        {
            $query .= '&sortBy=changeDate';
        }

        // Build the navigationUri
        unset($get['from']);
        unset($get['to']);
        $navigationUri = '?'.http_build_query($get);

        // Make the query to geonetwork (including from/to and facets filtering).
        $body = $this->makeQuery('srv/eng/search/q?fast=index&'.$query);

        // Retrieve results.
        $crawler = new Crawler((string)$body);

        $from = $crawler->attr('from');
        $to = $crawler->attr('to');
        $total = $crawler->filterXPath('//summary')->attr('count');

        // Current facet filtering.
        $breadcrumbQuery = $request->query->all();
        unset($breadcrumbQuery['from']);
        unset($breadcrumbQuery['to']);
        unset($breadcrumbQuery['sortBy']);

        // We build breadcrumbs and summary (facet filtering).
        $breadcrumbs = [];
        $hasFilter = false;
        $summary = [];

        $summaryResults = $crawler->filterXPath('//summary/*');
        foreach ($summaryResults as $domElement) {
            //print $domElement->nodeName.'<br/>';
            $summary[$domElement->nodeName] = [];
            $children = new Crawler($domElement);
            foreach ($children->children() as $child)
            {
                $isFilter = false;
                // Skip already queried taxonomies
                if (isset($breadcrumbQuery[$child->nodeName]) &&
                    in_array($child->getAttribute('name'), $breadcrumbQuery[$child->nodeName]))
                {
                    // Compute the url for removing this filter
                    $query = $breadcrumbQuery;
                    if (isset($query[$child->nodeName]) &&
                        in_array($child->getAttribute('name'), $query[$child->nodeName]))
                    {
                        $found = array_search($child->getAttribute('name'), $query[$child->nodeName]);
                        array_splice($query[$child->nodeName], $found);
                        if (count($query[$child->nodeName])===0)
                        {
                            unset($query[$child->nodeName]);
                        }
                    }
                    $url = '?'.http_build_query($query);

                    if (!isset($breadcrumbs[$domElement->nodeName]))
                    {
                        $breadcrumbs[$domElement->nodeName] = [];
                    }
                    $breadcrumbs[$domElement->nodeName] []= [
                        'name' => $child->getAttribute('name'),
                        'url' => $url,
                    ];
                    /*$breadcrumbs []= [
                        'type' => $child->nodeName,
                        'value' => $child->getAttribute('name'),
                        'url' => $url,
                    ];*/
                    continue;/**/
                    /*$hasFilter = true;
                    $isFilter = true;*/
                }
                $summary[$domElement->nodeName] []= [
                    'taxonomy'=>$child->nodeName,
                    'name'=>$child->getAttribute('name'),
                    'count'=>$child->getAttribute('count'),
                    /*'isFilter' => $isFilter,*/
                ];
            }
        }
       
        // Sort $summary according to: geoDescCodes, keywords, orgNames, formats, createDateYears
        $keyOrder = ['geoDescCodes', 'keywords', 'orgNames', 'formats', 'createDateYears'];
        uksort($summary, function($a, $b) use ($keyOrder) {
            return array_search($a, $keyOrder) - array_search($b, $keyOrder);
        });
        sort($summary['geoDescCodes']);

        // Collect metadata informations.
        $maps = [];
        $metadataResults = $crawler->filterXPath('//metadata');
        foreach ($metadataResults as $domElement) {
            $metadata = new Crawler($domElement);

            $map = [];
            $map['title'] = $metadata->filterXPath('//title')->text();
            $map['uuid'] = $metadata->filterXPath('//geonet:info/uuid')->text();
            $map['abstract'] = $metadata->filterXPath('//abstract')->text();
            if ($metadata->filterXPath('//geoBox')->count())
            {
                $geoBox = $metadata->filterXPath('//geoBox')->text();
                $map['geobox'] = $geoBox;
            }
            if ($metadata->filterXPath('//image')->count())
            {
                $image = $metadata->filterXPath('//image')->text();
                $image = explode('|', $image);
                $map['image'] = $this->remapUrls($image[1]).'&width=184&height=140';
            }
            $maps []= $map;
        }

        return [
            'maps' => $maps,
            'from' => $from,
            'to' => $to,
            'limit' => $this->itemsPerResultPage,
            'total' => $total,
            'summary' => $summary,
            'breadcrumbs' => $breadcrumbs,
            'hasFilter' => $hasFilter,
            'baseUri' => $baseUri,
            'navigationUri' => $navigationUri,
            'clearFilterUrl' => $clearFilterUrl,
        ];
    }

    /**
     * @Route("/resource")
     * @Security("has_role('ROLE_USER')")
     */
    public function resourceAction(Request $request)
    {
        $get = $request->query->all();
        $query = '?'.http_build_query($get);

        // Retrieve the full metadata to compute cash entry.
        $uuid = $request->query->get('uuid');
        $metadataContent = $this->makeQuery('srv/eng/xml.metadata.get?uuid='.$uuid);
        $cachedEntry = md5($metadataContent.$query);
        $cachedFilename = 'cache/'.$cachedEntry.'.jpg';
        if (file_exists($cachedFilename))
        {
            return new BinaryFileResponse($cachedFilename);
        }

        try
        {
            $geonetworkResponse = $this->makeQuery('srv/eng/resources.get'.$query, false);
        }
        catch (BadResponseException $e)
        {
            // @todo See why we cannot access the metadata resource.
            throw new AccessDeniedException();
        }

        $response = new Response();
        $response->setContent((string)$geonetworkResponse->getBody());
        $mimeType = $geonetworkResponse->getContentType();
        if ($mimeType==='application/png')
        {
            $mimeType = 'image/png';
        }
        else if ($mimeType==='application/binary')
        {
            // Try to manage strange mime types returned by Geonetwork.
            $fname = strtolower($request->query->get('fname'));
            if (strpos($fname, '.jpg')!==false)
            {
                $mimeType = 'image/jpg';
            }
            else if (strpos($fname, '.ong')!==false)
            {
                $mimeType = 'image/png';
            }
        }
        $response->headers->set('Content-Type', $mimeType);

        // Manage thumbnail and cache here.
        if (strpos($mimeType, 'image/')===0 &&
            (isset($get['width']) || isset($get['height'])))
        {
            $image = imagecreatefromstring($geonetworkResponse->getBody());
            if ($image!==false)
            {
                $srcWidth = imagesx($image);
                $srcHeight = imagesy($image);

                if (!isset($get['height']))
                {
                    $get['height'] = $get['width'];
                }

                $scaleX = $get['width'] / $srcWidth;
                $scaleY = $get['height'] / $srcHeight;
                $scale = max($scaleX, $scaleY);
                $dstWidth = min($get['width'], $srcWidth * $scale);
                $dstHeight = min($get['height'], $srcHeight * $scale);
                // We crop the left part of the image.
                $regionX = $srcWidth - $dstWidth / $scale;
                // We crop the top part of the image.
                $regionY = $srcHeight - $dstHeight / $scale;
                $regionWidth = $dstWidth / $scale;
                $regionHeight = $dstHeight / $scale;

                $resized = imagecreatetruecolor($dstWidth, $dstHeight);
                imagecopyresampled($resized, $image, 0, 0, $regionX, $regionY, $dstWidth, $dstHeight, $regionWidth, $regionHeight);

                ob_start();
                imagejpeg($resized, null, 90);
                $content = ob_get_clean(); 
                $response->setContent((string)$content);

                if (isset($cachedFilename))
                {
                    file_put_contents($cachedFilename, $content);
                }
            }
        }/**/

        if (isset($get['access'], $get['fname']) && $get['access']==='private')
        {
            $disposition = $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $get['fname']
            );

            $response->headers->set('Content-Disposition', $disposition);
        }

        return $response;
    }

    /**
     * @Route("/view")
     * @Template
     * @Security("has_role('ROLE_USER')")
     */
    public function viewAction(Request $request)
    {
        $uuid = $request->query->get('uuid', null);
        if ($uuid===null)
        {
            return $this->redirect($this->generateUrl('cartong_msf_mapcentre_index'));
        }

        $body = $this->makeQuery('srv/eng/xml.metadata.get?uuid='.$uuid);

        $crawler = new Crawler((string)$body);

        // Extract information by some xpath queries.
        $keywords = $crawler->filterXPath('//gmd:keyword/gco:CharacterString')->extract(['_text']);
        $keywords = array_filter($keywords);
        $keywords = implode(', ', $keywords);

        $countries = $crawler->filterXPath('//gmd:code/gco:CharacterString')->extract(['_text']);
        $countries = array_filter($countries);
        $countries = implode(', ', $countries);

        $orgNames = $crawler->filterXPath('//gmd:pointOfContact/gmd:CI_ResponsibleParty/gmd:organisationName/gco:CharacterString')->extract(['_text']);
        $orgNames = array_filter($orgNames);
        $orgNames = implode(', ', $orgNames);

        $classification = $crawler->filterXPath('//gmd:resourceConstraints/gmd:MD_SecurityConstraints/gmd:classification/gmd:MD_ClassificationCode')->extract(['codeListValue']);
        $classification = array_filter($classification);
        if (count($classification)===0)
        {
            $classification []= 'public';
        }
        $classification = implode(', ', $classification);

        $printDimensions = $crawler->filterXPath('//gmd:denominator/gco:Integer')->extract(['_text']);
        if (count($printDimensions)!==0)
        {
            $printDimensions = $printDimensions[0];
        }
        else
        {
            $printDimensions = $this->get('translator')->trans('unknown');
        }

        $geoBox = [];
        // The order WSEN is the same as in geobox returned by the search service.
        $geoBox []= $crawler->filterXPath('//gmd:westBoundLongitude/gco:Decimal')->text();
        $geoBox []= $crawler->filterXPath('//gmd:southBoundLatitude/gco:Decimal')->text();
        $geoBox []= $crawler->filterXPath('//gmd:eastBoundLongitude/gco:Decimal')->text();
        $geoBox []= $crawler->filterXPath('//gmd:northBoundLatitude/gco:Decimal')->text();
        $geoBox = implode('|', $geoBox);

        $ownerLogos = [];
        $owners = explode(', ', $orgNames);
        foreach ($owners as $owner)
        {
            $logoFilename = 'bundles/cartongmsf/images/logo-'.$owner.'.png';
            if (file_exists($logoFilename))
            {
                $ownerLogos[$owner] = $this->get('templating.helper.assets')->getUrl($logoFilename);
            }
        }
        
        $thumbnail = 'http://placehold.it/800x600';
        $thumbnailQuery = $crawler->filterXPath('//gmd:graphicOverview/gmd:MD_BrowseGraphic/gmd:fileName/gco:CharacterString');
        if ($thumbnailQuery->count()!==0)
        {
            $thumbnail = $thumbnailQuery->text();
        }

        $url = '#';
        $urlQuery = $crawler->filterXPath('//gmd:transferOptions/gmd:MD_DigitalTransferOptions/gmd:onLine/gmd:CI_OnlineResource/gmd:linkage/gmd:URL');
        if ($urlQuery->count()!==0)
        {
            $url = $urlQuery->text();
        }

        // Retrieve the metadata size using a HEAD request.
        $headUrl = $this->remapUrls($url);
        $resourceUrl = $this->generateUrl('cartong_msf_mapcentre_resource', [], true);
        $headUrl = str_replace($resourceUrl, 'srv/eng/resources.get', $headUrl);
        $headUrl = str_replace('srv/en/', 'srv/eng/', $headUrl);
        $headUrl = str_replace('http://msfmapcentre.cartong.org/geonetwork/', '', $headUrl);
        $contentSize = '';
        try
        {
            $headResponse = $this->makeQuery($headUrl, false, 'head');
            $contentSize = (string)$headResponse->getHeader('Content-Length');
        }
        catch (BadResponseException $exception)
        {
            // @todo Notify an error or see why data is not accessible as it should be.
        }

        $data = [
            'title' => $crawler->filterXPath('//gmd:title/gco:CharacterString')->text(),
            'thumbnail' => $this->remapUrls($thumbnail),
            'url' => $this->remapUrls($url),
            'contentSize' => $contentSize,
            'abstract' => $crawler->filterXPath('//gmd:abstract/gco:CharacterString')->text(),
            'publicationDate' => $crawler->filterXPath('//gmd:date/gco:Date')->text(),
			'printDimensions' => $printDimensions,//$crawler->filterXPath('//gmd:denominator/gco:Integer')->text(),
            //'format' => $format,
            'keywords' => $keywords,
            'countries' => $countries,
            'geobox' => $geoBox,
            'owner' => $orgNames,
            'ownerLogos' => $ownerLogos,
            'author' => 'GIS UNIT',
            'confidentialityLevel' => $classification,
        ];

        return $data;

        return [
            'content'=>$body,
        ];
    }

    /**
     * @Route("/submission")
     * @Method("GET")
     * @Security("has_role('ROLE_USER')")
     */
    public function submissionAction(Request $request)
    {
        $locale = $request->getLocale();
        if ($locale==='fr')
        {
            return $this->render('CartongMSFBundle:MapCentre:submission-fr.html.twig');
        }
        return $this->render('CartongMSFBundle:MapCentre:submission.html.twig');
    }

    /**
     * @Route("/submission")
     * @Method("POST")
     * @Security("has_role('ROLE_USER')")
     */
    public function postSubmissionAction(Request $request)
    {
        $recipient = $this->container->getParameter('mailer_recipient');

        $name = $request->request->get('name');
        $email = $request->request->get('email');
		$comment = $request->request->get('comment');
        $file = $request->files->get('file');
        $attachmentFilename = $file->getClientOriginalName();
        $attachmentRealFilename = $file->getPathname();

        try
        {
            $message = \Swift_Message::newInstance()
                ->setSubject('[MSF] Mapcenter submission')
                ->setFrom(array($email => $name))
                ->setTo(array($recipient))
                ->setBody($comment,'This message was sent from the MSF Mapcenter website. Clik on the .dat -->' )
                //->addPart('<q>Here is the message itself</q>', 'text/html')
                ->attach(\Swift_Attachment::fromPath($attachmentRealFilename)->setFilename($attachmentFilename))
                ;
            // The following line should work be we need to instantiate the SmtpTransport manually.
            //$this->get('mailer')->send($message);
            $transport = \Swift_SmtpTransport::newInstance($this->container->getParameter('mailer_host'), $this->container->getParameter('mailer_port'))
                ->setUsername($this->container->getParameter('mailer_user'))
                ->setPassword($this->container->getParameter('mailer_password'));
            $mailer = \Swift_Mailer::newInstance($transport);
            $mailer->send($message);/**/
        }
        catch (\Exception $e)
        {
            $this->get('session')->getFlashBag()->add(
                'error',
                $this->get('translator')->trans('The message cannot be sent, please try again')
            );
            return $this->redirect($this->generateUrl('cartong_msf_mapcentre_submission'));
        }

        $this->get('session')->getFlashBag()->add(
            'notice',
            'Your submission has been sent'
        );
        
        return $this->redirect($this->generateUrl('cartong_msf_mapcentre_submission'));
    }

    /**
     * @Route("/help")
     * @Security("has_role('ROLE_USER')")
     */
    public function helpAction(Request $request)
    {
        $locale = $request->getLocale();
        if ($locale==='fr')
        {
            return $this->render('CartongMSFBundle:MapCentre:help-fr.html.twig');
        }
        return $this->render('CartongMSFBundle:MapCentre:help.html.twig');
    }

    /**
     * @Route("/contact")
     * @Template
     * @Security("has_role('ROLE_USER')")
     */
    public function contactAction(Request $request)
    {
        return [];
    }

    /**
     * @Route("/tools")
     * @Template
     * @Security("has_role('ROLE_USER')")
     */
    public function toolsAction(Request $request)
    {
        return [];
    }
	/**
     * @Route("/about")
     * @Security("has_role('ROLE_USER')")
     */
    public function aboutAction(Request $request)
    {
        $locale = $request->getLocale();
        if ($locale==='fr')
        {
            return $this->render('CartongMSFBundle:MapCentre:about-fr.html.twig');
        }
        return $this->render('CartongMSFBundle:MapCentre:about.html.twig');
    }

    /**
     * @Route("/credit")
     * @Template
     * @Security("has_role('ROLE_USER')")
     */
    public function creditAction(Request $request)
    {
        return [];
    }

    /**
     * @Route("/disclaimer")
     * @Template
     * @Security("has_role('ROLE_USER')")
     */
    public function disclaimerAction(Request $request)
    {
        return [];
    }
	
	
    /**
     * @Route("/emergenciesAdmin")
     * @Template
     * @Method("GET")
     * @Security("has_role('ROLE_ADMIN')")
     */
    public function emergenciesAdminAction(Request $request)
    {
        $body = $this->makeQuery('srv/eng/search/q');

        $emergencies = [];
        if (file_exists($this->emergenciesFile))
        {
            $emergencies = json_decode(file_get_contents($this->emergenciesFile));
            $emergencies = array_map(function($emergency) {
                return $emergency->country;
            }, $emergencies);
        }

        $crawler = new Crawler((string)$body);
        $countries = [];
        $geoDescCodes = $crawler->filterXPath('//geoDescCode');
        foreach ($geoDescCodes as $geoDescCode) {
            $country = [
                'name'=>$geoDescCode->getAttribute('name'),
            ];
            if (in_array($country['name'], $emergencies))
            {
                $country['checked'] = true;
            }
            $countries []= $country;
        }

        return ['countries'=>$countries];
    }

    /**
     * @Route("/emergenciesAdmin")
     * @Method("POST")
     * @Security("has_role('ROLE_ADMIN')")
     */
    public function postEmergenciesAdminAction(Request $request)
    {
        // Make an array of country
        $countries = $request->request->get('emergencies');

        $emergencies = [];
        if ($countries!==null)
        {
            foreach ($countries as $country)
            {
                $emergencies []= [
                    'country'=>$country,
                ];
            }
        }

        $json = json_encode($emergencies);
        file_put_contents($this->emergenciesFile, $json);

        return new RedirectResponse($this->generateUrl('cartong_msf_mapcentre_emergenciesadmin'));
    }

    /**
     * @Route("/suggest")
     * @Method("GET")
     * @Security("has_role('ROLE_USER')")
     */
    public function suggestAction(Request $request)
    {
        $query = $request->query->get('q');
        $body = $this->makeQuery('srv/eng/main.search.suggest?field=any&sortBy=STARTSWITHFIRST&q='.$query);

        $result = json_decode((string)$body);

        $response = new JsonResponse;
        $response->setData($result);

        return $response;
    }

}
