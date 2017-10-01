<?php

namespace I18nBundle\EventListener;

use I18nBundle\Definitions;
use I18nBundle\Event\ContextSwitchEvent;
use I18nBundle\Helper\DocumentHelper;
use I18nBundle\Helper\UserHelper;
use I18nBundle\Helper\ZoneHelper;
use I18nBundle\I18nEvents;
use I18nBundle\Manager\ContextManager;
use I18nBundle\Manager\PathGeneratorManager;
use I18nBundle\Manager\ZoneManager;
use I18nBundle\Tool\System;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use Pimcore\Cache;
use Pimcore\Logger;

use Pimcore\Model\Document;
use Pimcore\Http\Request\Resolver\DocumentResolver;
use Pimcore\Http\Request\Resolver\PimcoreContextResolver;
use Pimcore\Bundle\CoreBundle\EventListener\Traits\PimcoreContextAwareTrait;

class DetectorListener implements EventSubscriberInterface
{
    use PimcoreContextAwareTrait;

    /**
     * @var string
     */
    private $i18nType = 'language';

    /**
     * @var string
     */
    private $defaultLanguage = NULL;

    /**
     * @var string
     */
    private $defaultCountry = NULL;

    /**
     * @var array
     */
    private $validLanguages = [];

    /**
     * @var array
     */
    private $validCountries = [];

    /**
     * @var null
     */
    private $globalPrefix = NULL;

    /**
     * @var \Pimcore\Model\Document
     */
    private $document = NULL;

    /**
     * @var ZoneManager
     */
    protected $zoneManager;

    /**
     * @var ContextManager
     */
    protected $contextManager;

    /**
     * @var PathGeneratorManager
     */
    protected $pathGeneratorManager;

    /**
     * @var DocumentResolver
     */
    protected $documentResolver;

    /**
     * @var DocumentHelper
     */
    protected $documentHelper;

    /**
     * @var ZoneHelper
     */
    protected $zoneHelper;

    /**
     * @var UserHelper
     */
    protected $userHelper;

    /**
     * @var Request
     */
    protected $request;

    /**
     * DetectorListener constructor.
     *
     * @param DocumentResolver     $documentResolver
     * @param ZoneManager          $zoneManager
     * @param ContextManager       $contextManager
     * @param PathGeneratorManager $pathGeneratorManager
     * @param DocumentHelper       $documentHelper
     * @param ZoneHelper           $zoneHelper
     * @param UserHelper           $userHelper
     */
    public function __construct(
        DocumentResolver $documentResolver,
        ZoneManager $zoneManager,
        ContextManager $contextManager,
        PathGeneratorManager $pathGeneratorManager,
        DocumentHelper $documentHelper,
        ZoneHelper $zoneHelper,
        UserHelper $userHelper
    ) {
        $this->documentResolver = $documentResolver;
        $this->zoneManager = $zoneManager;
        $this->contextManager = $contextManager;
        $this->pathGeneratorManager = $pathGeneratorManager;
        $this->documentHelper = $documentHelper;
        $this->zoneHelper = $zoneHelper;
        $this->userHelper = $userHelper;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 20], //before responseExceptionListener
            KernelEvents::REQUEST   => ['onKernelRequest']
        ];
    }

    private function initI18nSystem($request)
    {
        //initialize all managers!
        $this->zoneManager->initZones();
        $this->contextManager->initContext($this->zoneManager->getCurrentZoneInfo('mode'));
        $this->pathGeneratorManager->initPathGenerator($request->attributes->get('pimcore_request_source'));
    }

    /**
     * @param GetResponseForExceptionEvent $event
     */
    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        if ($event->isMasterRequest() === FALSE) {
            return;
        }

        $this->initI18nSystem($event->getRequest());
        $this->document = $this->documentResolver->getDocument($this->request);

        //fallback.
        Cache\Runtime::set('i18n.languageIso', strtolower($event->getRequest()->getLocale()));
        Cache\Runtime::set('i18n.countryIso', Definitions::INTERNATIONAL_COUNTRY_NAMESPACE);
    }

    /**
     * @param GetResponseEvent $event
     *
     * @throws \Exception
     */
    public function onKernelRequest(GetResponseEvent $event)
    {
        if ($event->isMasterRequest() === FALSE) {
            return;
        }

        $this->request = $event->getRequest();
        if (!$this->matchesPimcoreContext($this->request, PimcoreContextResolver::CONTEXT_DEFAULT)) {
            return;
        }

        $this->document = $this->documentResolver->getDocument($this->request);
        if (!$this->document) {
            return;
        }

        if (!$this->isValidI18nCheckRequest(TRUE)) {
            return;
        }

        $this->initI18nSystem($this->request);

        $this->i18nType = $this->zoneManager->getCurrentZoneInfo('mode');
        $this->validLanguages = $this->zoneManager->getCurrentZoneLanguageAdapter()->getActiveLanguages();
        $this->defaultLanguage = $this->zoneManager->getCurrentZoneLanguageAdapter()->getDefaultLanguage();

        if ($this->i18nType === 'country') {
            $this->validCountries = $this->zoneManager->getCurrentZoneCountryAdapter()->getActiveCountries();
            $this->defaultCountry = $this->zoneManager->getCurrentZoneCountryAdapter()->getDefaultCountry();
        }

        $globalPrefix = $this->zoneManager->getCurrentZoneInfo('global_prefix');
        if ($globalPrefix !== FALSE) {
            $this->globalPrefix = $globalPrefix;
        }

        if ($this->document instanceof Document\Hardlink\Wrapper\WrapperInterface) {
            $documentCountry = $this->document->getHardLinkSource()->getProperty('country');
            $documentLanguage = $this->document->getHardLinkSource()->getProperty('language');
        } else {
            $documentCountry = $this->document->getProperty('country');
            $documentLanguage = $this->document->getProperty('language');
        }

        /**
         * If a hardlink is requested e.g. /en-us, pimcore gets the locale from the source, which is "quite" wrong.
         */
        $requestLocale = $this->request->getLocale();
        if($this->document instanceof Document\Hardlink\Wrapper\WrapperInterface) {
            $hardLinkSourceLanguage = $this->document->getHardLinkSource()->getProperty('language');
            if(!empty($hardLinkSourceLanguage) && $hardLinkSourceLanguage !== $requestLocale) {
                $this->request->setLocale($hardLinkSourceLanguage);
            }
        }

        $currentRouteName = $this->request->get('_route');
        $requestSource = $this->request->attributes->get('pimcore_request_source');
        $validRoute = FALSE;

        if ($requestSource === 'staticroute' || $currentRouteName === 'document_' . $this->document->getId()) {
            $validRoute = TRUE;
        }

        if ($validRoute === TRUE && empty($documentLanguage)) {

            $siteId = 1;
            if (\Pimcore\Model\Site::isSiteRequest() === TRUE) {
                $site = \Pimcore\Model\Site::getCurrentSite();
                $siteId = $site->getRootId();
            }

            //if document is root, no language tag is required
            if ($this->document->getId() !== $siteId) {
                throw new \Exception(get_class($this->document) . ' (' . $this->document->getId() . ') does not have a valid language property!');
            }
        }

        $currentCountry = FALSE;
        $currentLanguage = FALSE;

        $validCountry = !empty($documentCountry) && array_search(strtoupper($documentCountry), array_column($this->validCountries, 'isoCode')) !== FALSE;
        $validLanguage = !empty($documentLanguage) && array_search($documentLanguage, array_column($this->validLanguages, 'isoCode')) !== FALSE;

        // @todo: currently, redirect works only with pimcore documents and static routes. symfony routes will be ignored.
        if ($validRoute) {
            if ($this->i18nType === 'language') {
                //first get valid language
                if (!$validLanguage) {
                    if ($this->canRedirect() && $this->i18nType === 'language') {
                        $url = $this->getRedirectUrl($this->getLanguageUrl());
                        $event->setResponse(new RedirectResponse($url));
                        return;
                    }
                }
            } else if ($this->i18nType === 'country') {
                //we are wrong. redirect user!
                if ($this->canRedirect() && (!$validCountry || !$validLanguage)) {
                    $url = $this->getRedirectUrl($this->getCountryUrl());
                    $event->setResponse(new RedirectResponse($url));
                    return;
                }
            }
        }

        //Set Locale.
        if ($validLanguage === TRUE) {
            if (strpos($documentLanguage, '_') !== FALSE) {
                $parts = explode('_', $documentLanguage);
                $currentLanguage = $parts[0];
            } else {
                $currentLanguage = $documentLanguage;
            }

            Cache\Runtime::set('i18n.languageIso', strtolower($currentLanguage));
        }

        //Set Country. This variable is only !false if i18n country is active
        if ($validCountry === TRUE) {
            $currentCountry = strtoupper($documentCountry);
            Cache\Runtime::set('i18n.countryIso', $currentCountry);
        }

        $currentZoneId = $this->zoneManager->getCurrentZoneInfo('zoneId');

        //check if zone, language or country has been changed, trigger event for 3th party.
        $this->detectContextSwitch($currentZoneId, $currentLanguage, $currentCountry);

        //update session
        $this->updateSessionData($currentZoneId, $currentLanguage, $currentCountry);
    }

    /**
     * Important: ContextSwitch only works in same domain levels.
     * Since there is no way for simple cross-domain session ids, the zone switch will be sort of useless most of the time. :(
     *
     * @param $currentZoneId
     * @param $currentLanguage
     * @param $currentCountry
     *
     * @return void
     */
    private function detectContextSwitch($currentZoneId, $currentLanguage, $currentCountry)
    {
        if (!$this->isValidI18nCheckRequest()) {
            return;
        }

        $session = $this->getSessionData();

        $languageHasSwitched = FALSE;
        $countryHasSwitched = FALSE;
        $zoneHasSwitched = FALSE;

        if (is_null($session['lastLanguage']) || (!is_null($session['lastLanguage']) && $currentLanguage !== $session['lastLanguage'])) {
            $languageHasSwitched = TRUE;
        }

        if ($session['lastCountry'] !== FALSE && (!is_null($session['lastCountry']) && $currentCountry !== $session['lastCountry'])) {
            $countryHasSwitched = TRUE;
        }

        if ($currentZoneId !== $session['lastZoneId']) {
            $zoneHasSwitched = TRUE;
        }

        if ($zoneHasSwitched || $languageHasSwitched || $countryHasSwitched) {

            $params = [
                'zoneHasSwitched'     => $zoneHasSwitched,
                'zoneFrom'            => $session['lastZoneId'],
                'zoneTo'              => $currentZoneId,
                'languageHasSwitched' => $languageHasSwitched,
                'languageFrom'        => $session['lastLanguage'],
                'languageTo'          => $currentLanguage,
                'countryHasSwitched'  => $countryHasSwitched,
                'countryFrom'         => $session['lastCountry'],
                'countryTo'           => $currentCountry
            ];

            if ($zoneHasSwitched === TRUE) {
                Logger::log(
                    sprintf(
                        'switch zone: from %s to %s. triggered by: %s',
                        $session['lastZoneId'],
                        $currentZoneId,
                        $this->request->getRequestUri()
                    )
                );
            }

            if ($languageHasSwitched === TRUE) {
                Logger::log(
                    sprintf(
                        'switch language: from %s to %s. triggered by: %s',
                        $session['lastLanguage'],
                        $currentLanguage,
                        $this->request->getRequestUri()
                    )
                );
            }

            if ($countryHasSwitched === TRUE) {
                Logger::log(
                    sprintf(
                        'switch country: from %s to %s. triggered by: %s',
                        $session['lastCountry'],
                        $currentCountry,
                        $this->request->getRequestUri()
                    )
                );
            }

            \Pimcore::getEventDispatcher()->dispatch(
                I18nEvents::CONTEXT_SWITCH,
                new ContextSwitchEvent($params)
            );
        }
    }

    /**
     * Returns absolute Url to website with language-country context.
     * Because this could be a different domain, absolute url is necessary
     * @return bool|string
     */
    private function getCountryUrl()
    {
        $userLanguageIso = $this->userHelper->guessLanguage($this->validLanguages);
        $userCountryIso = $this->userHelper->guessCountry($this->validCountries);

        $matchUrl = $this->zoneHelper->findUrlInZoneTree(
            $this->zoneManager->getCurrentZoneDomains(TRUE),
            $userLanguageIso,
            $this->defaultLanguage,
            $userCountryIso,
            $this->defaultCountry
        );

        return $matchUrl;
    }

    /**
     * Returns absolute Url to website with language context.
     * Because this could be a different domain, absolute url is necessary
     * @return bool|string
     */
    private function getLanguageUrl()
    {
        $userLanguageIso = $this->userHelper->guessLanguage($this->validLanguages);
        $defaultLanguageIso = $this->defaultLanguage;

        $matchUrl = $this->zoneHelper->findUrlInZoneTree(
            $this->zoneManager->getCurrentZoneDomains(TRUE),
            $userLanguageIso,
            $defaultLanguageIso
        );

        return $matchUrl;
    }

    /**
     * @return array
     */
    private function getSessionData()
    {
        /** @var \Symfony\Component\HttpFoundation\Session\Attribute\NamespacedAttributeBag $bag */
        $bag = $this->request->getSession()->getBag('i18n_session');

        $data = [
            'lastLanguage' => NULL,
            'lastCountry' => NULL,
            'lastZoneId'   => NULL
        ];

        if ($bag->has('lastLanguage')) {
            $data['lastLanguage'] = $bag->get('lastLanguage');
        }

        if ($bag->get('lastCountry')) {
            $data['lastCountry'] = $bag->get('lastCountry');
        }

        //if no zone as been defined, zone id is always NULL.
        $data['lastZoneId'] = $bag->get('lastZoneId');

        return $data;
    }

    /**
     * @param null|int $currentZoneId
     * @param bool     $languageData
     * @param bool     $countryData
     *
     * @return void
     */
    private function updateSessionData($currentZoneId = NULL, $languageData = FALSE, $countryData = FALSE)
    {
        if (!$this->isValidI18nCheckRequest()) {
            return;
        }

        /** @var \Symfony\Component\HttpFoundation\Session\Attribute\NamespacedAttributeBag $bag */
        $bag = $this->request->getSession()->getBag('i18n_session');

        if ($languageData !== FALSE) {
            $bag->set('lastLanguage', $languageData);
        }

        if ($countryData !== FALSE) {
            $bag->set('lastCountry', $countryData);
        }

        $bag->set('lastZoneId', $currentZoneId);
    }

    /**
     * @param $path
     *
     * @return string
     */
    private function getRedirectUrl($path)
    {
        $config = \Pimcore\Config::getSystemConfig();

        $endPath = rtrim($path, '/');

        if ($config->documents->allowtrailingslash !== 'no') {
            $endPath = $endPath . '/';
        }

        return $endPath;
    }

    /**
     * @param bool $allowAjax
     *
     * @return bool
     */
    private function isValidI18nCheckRequest($allowAjax = FALSE)
    {
        if (System::isInCliMode() || ($allowAjax === FALSE && $this->request->isXmlHttpRequest())) {
            return FALSE;
        }

        return TRUE;
    }

    private function canRedirect()
    {
        return !System::isInBackend($this->request);
    }
}