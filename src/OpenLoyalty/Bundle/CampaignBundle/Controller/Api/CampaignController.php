<?php
/**
 * Copyright © 2017 Divante, Inc. All rights reserved.
 * See LICENSE for license details.
 */
namespace OpenLoyalty\Bundle\CampaignBundle\Controller\Api;

use Broadway\CommandHandling\CommandBus;
use Broadway\UuidGenerator\UuidGeneratorInterface;
use FOS\RestBundle\Context\Context;
use FOS\RestBundle\Controller\Annotations\QueryParam;
use FOS\RestBundle\Controller\Annotations\Route;
use FOS\RestBundle\Controller\Annotations\View;
use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\Request\ParamFetcher;
use FOS\RestBundle\View\View as FosView;
use FOS\RestBundle\View\ViewHandlerInterface;
use Nelmio\ApiDocBundle\Annotation\ApiDoc;
use OpenLoyalty\Bundle\CampaignBundle\Exception\CampaignLimitException;
use OpenLoyalty\Bundle\CampaignBundle\Exception\CampaignUsageChange\CampaignUsageChangeException;
use OpenLoyalty\Bundle\CampaignBundle\Exception\InvalidTransactionException;
use OpenLoyalty\Bundle\CampaignBundle\Exception\NoCouponsLeftException;
use OpenLoyalty\Bundle\CampaignBundle\Exception\NotAllowedException;
use OpenLoyalty\Bundle\CampaignBundle\Exception\NotEnoughPointsException;
use OpenLoyalty\Bundle\CampaignBundle\Exception\TransactionRequiredException;
use OpenLoyalty\Bundle\CampaignBundle\Form\Type\CampaignFormType;
use OpenLoyalty\Bundle\CampaignBundle\Form\Type\CampaignPhotoFormType;
use OpenLoyalty\Bundle\CampaignBundle\Form\Type\EditCampaignFormType;
use OpenLoyalty\Bundle\CampaignBundle\Form\Type\CampaignBrandIconFormType;
use OpenLoyalty\Bundle\CampaignBundle\Model\Campaign;
use OpenLoyalty\Bundle\CampaignBundle\ResponseModel\CouponUsageResponse;
use OpenLoyalty\Bundle\CampaignBundle\Service\CampaignPhotoUploader;
use OpenLoyalty\Bundle\CampaignBundle\Service\CampaignProvider;
use OpenLoyalty\Bundle\CampaignBundle\Service\CampaignValidator;
use OpenLoyalty\Bundle\CampaignBundle\Service\MultipleCampaignCouponUsageProvider;
use OpenLoyalty\Bundle\CampaignBundle\Service\CampaignBrandIconUploader;
use OpenLoyalty\Bundle\CampaignBundle\Service\CampaignFileUploader;
use OpenLoyalty\Bundle\CoreBundle\Service\CSVGenerator;
use OpenLoyalty\Bundle\MarkDownBundle\Service\FOSContextProvider;
use OpenLoyalty\Bundle\PaginationBundle\Service\Paginator;
use OpenLoyalty\Bundle\UserBundle\Service\EmailProvider;
use OpenLoyalty\Bundle\UserBundle\Service\EsParamManager;
use OpenLoyalty\Component\Campaign\Domain\Campaign as DomainCampaign;
use OpenLoyalty\Component\Campaign\Domain\CampaignId;
use OpenLoyalty\Component\Campaign\Domain\Command\BuyCampaign;
use OpenLoyalty\Component\Campaign\Domain\Command\ChangeCampaignState;
use OpenLoyalty\Component\Campaign\Domain\Command\CreateCampaign;
use OpenLoyalty\Component\Campaign\Domain\Command\RemoveCampaignBrandIcon;
use OpenLoyalty\Component\Campaign\Domain\Command\RemoveCampaignPhoto;
use OpenLoyalty\Component\Campaign\Domain\Command\SetCampaignBrandIcon;
use OpenLoyalty\Component\Campaign\Domain\Command\SetCampaignPhoto;
use OpenLoyalty\Component\Campaign\Domain\Command\UpdateCampaign;
use OpenLoyalty\Component\Campaign\Domain\Coupon\CouponCodeProvider;
use OpenLoyalty\Component\Campaign\Domain\CustomerId;
use OpenLoyalty\Component\Campaign\Domain\LevelId;
use OpenLoyalty\Component\Campaign\Domain\ReadModel\ActiveCampaigns;
use OpenLoyalty\Component\Campaign\Domain\ReadModel\CampaignBoughtRepository;
use OpenLoyalty\Component\Campaign\Domain\ReadModel\CampaignShortInfo;
use OpenLoyalty\Component\Campaign\Domain\SegmentId;
use OpenLoyalty\Component\Campaign\Domain\TransactionId;
use OpenLoyalty\Component\Campaign\Domain\Model\CampaignFile;
use OpenLoyalty\Component\Campaign\Infrastructure\Persistence\Doctrine\Repository\DoctrineCampaignRepository;
use OpenLoyalty\Component\Campaign\Infrastructure\Repository\CampaignBoughtElasticsearchRepository;
use OpenLoyalty\Component\Customer\Domain\CampaignId as CustomerCampaignId;
use OpenLoyalty\Component\Customer\Domain\Command\ChangeCampaignUsage;
use OpenLoyalty\Component\Customer\Domain\Model\CampaignPurchase;
use OpenLoyalty\Component\Customer\Domain\Model\Coupon;
use OpenLoyalty\Component\Customer\Domain\ReadModel\CustomerDetails;
use OpenLoyalty\Component\Customer\Domain\ReadModel\CustomerDetailsRepository;
use OpenLoyalty\Component\Segment\Domain\ReadModel\SegmentedCustomers;
use OpenLoyalty\Component\Segment\Domain\ReadModel\SegmentedCustomersRepository;
use OpenLoyalty\Component\Transaction\Domain\ReadModel\TransactionDetailsRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * Class CampaignController.
 */
class CampaignController extends FOSRestController
{
    /**
     * @var CommandBus
     */
    private $commandBus;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var CampaignValidator
     */
    private $campaignValidator;

    /**
     * @var CampaignProvider
     */
    private $campaignProvider;

    /**
     * @var CouponCodeProvider
     */
    private $couponCodeProvider;

    /**
     * @var TransactionDetailsRepository
     */
    private $transactionDetailsRepository;

    /**
     * @var CampaignBoughtRepository
     */
    private $campaignBoughtRepository;

    /**
     * @var CampaignBrandIconUploader
     */
    private $campaignBrandIconUploader;

    /**
     * @var FormFactoryInterface
     */
    private $formFactory;

    /**
     * @var UuidGeneratorInterface
     */
    private $uuidGenerator;

    /**
     * @var CampaignPhotoUploader
     */
    private $campaignPhotoUploader;

    /**
     * @var Paginator
     */
    private $paginator;

    /**
     * @var ParamFetcher
     */
    private $paramFetcher;

    /**
     * @var DoctrineCampaignRepository
     */
    private $campaignRepository;

    /**
     * @var EsParamManager
     */
    private $paramManager;

    /**
     * @var CSVGenerator
     */
    private $csvGenerator;

    /**
     * @var EmailProvider
     */
    private $customerEmailProvider;

    /**
     * @var CustomerDetailsRepository
     */
    private $customerDetailsRepository;

    /**
     * @var MultipleCampaignCouponUsageProvider
     */
    private $multipleCampaignCouponUsageProvider;

    /**
     * @var SegmentedCustomersRepository
     */
    private $segmentedCustomersRepository;

    /**
     * @var ViewHandlerInterface
     */
    private $viewHandler;

    /**
     * CampaignController constructor.
     *
     * @param CommandBus                            $commandBus
     * @param TranslatorInterface                   $translator
     * @param FormFactoryInterface                  $formFactory
     * @param ViewHandlerInterface                  $viewHandler
     * @param UuidGeneratorInterface                $uuidGenerator
     * @param Paginator                             $paginator
     * @param DoctrineCampaignRepository            $campaignRepository
     * @param CampaignValidator                     $campaignValidator
     * @param CampaignProvider                      $campaignProvider
     * @param CouponCodeProvider                    $couponCodeProvider
     * @param TransactionDetailsRepository          $transactionDetailsRepository
     * @param CampaignBrandIconUploader             $campaignBrandIconUploader
     * @param CampaignPhotoUploader                 $campaignPhotoUploader
     * @param ParamFetcher                          $paramFetcher
     * @param CampaignBoughtElasticsearchRepository $campaignBoughtRepository
     * @param EsParamManager                        $paramManager
     * @param CSVGenerator                          $csvGenerator
     * @param EmailProvider                         $customerEmailProvider
     * @param CustomerDetailsRepository             $customerDetailsRepository
     * @param MultipleCampaignCouponUsageProvider   $multipleCampaignCouponUsageProvider
     * @param SegmentedCustomersRepository          $segmentedCustomersRepository
     */
    public function __construct(
        CommandBus $commandBus,
        TranslatorInterface $translator,
        FormFactoryInterface $formFactory,
        ViewHandlerInterface $viewHandler,
        UuidGeneratorInterface $uuidGenerator,
        Paginator $paginator,
        DoctrineCampaignRepository $campaignRepository,
        CampaignValidator $campaignValidator,
        CampaignProvider $campaignProvider,
        CouponCodeProvider $couponCodeProvider,
        TransactionDetailsRepository $transactionDetailsRepository,
        CampaignBrandIconUploader $campaignBrandIconUploader,
        CampaignPhotoUploader $campaignPhotoUploader,
        ParamFetcher $paramFetcher,
        CampaignBoughtElasticsearchRepository $campaignBoughtRepository,
        EsParamManager $paramManager,
        CSVGenerator $csvGenerator,
        EmailProvider $customerEmailProvider,
        CustomerDetailsRepository $customerDetailsRepository,
        MultipleCampaignCouponUsageProvider $multipleCampaignCouponUsageProvider,
        SegmentedCustomersRepository $segmentedCustomersRepository
    ) {
        $this->commandBus = $commandBus;
        $this->translator = $translator;
        $this->formFactory = $formFactory;
        $this->viewHandler = $viewHandler;
        $this->uuidGenerator = $uuidGenerator;
        $this->paginator = $paginator;
        $this->campaignRepository = $campaignRepository;
        $this->campaignValidator = $campaignValidator;
        $this->campaignProvider = $campaignProvider;
        $this->couponCodeProvider = $couponCodeProvider;
        $this->transactionDetailsRepository = $transactionDetailsRepository;
        $this->campaignBoughtRepository = $campaignBoughtRepository;
        $this->campaignBrandIconUploader = $campaignBrandIconUploader;
        $this->campaignPhotoUploader = $campaignPhotoUploader;
        $this->paramFetcher = $paramFetcher;
        $this->paramManager = $paramManager;
        $this->csvGenerator = $csvGenerator;
        $this->customerEmailProvider = $customerEmailProvider;
        $this->customerDetailsRepository = $customerDetailsRepository;
        $this->multipleCampaignCouponUsageProvider = $multipleCampaignCouponUsageProvider;
        $this->segmentedCustomersRepository = $segmentedCustomersRepository;
    }

    /**
     * Create new campaign.
     *
     * @Route(name="oloy.campaign.create", path="/campaign")
     * @Method("POST")
     * @Security("is_granted('CREATE_CAMPAIGN')")
     *
     * @ApiDoc(
     *     name="Create new Campaign",
     *     section="Campaign",
     *     input={"class"="OpenLoyalty\Bundle\CampaignBundle\Form\Type\CampaignFormType", "name"="campaign"},
     *     statusCodes={
     *          200="Returned when successful",
     *          400="Returned when there are errors in form",
     *          404="Returned when campaign not found"
     *     }
     * )
     *
     * @View(serializerGroups={"admin", "Default"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function createAction(Request $request): Response
    {
        $form = $this->formFactory->createNamed('campaign', CampaignFormType::class);
        $form->handleRequest($request);

        if ($form->isValid()) {
            /** @var Campaign $data */
            $data = $form->getData();

            $id = new CampaignId($this->uuidGenerator->generate());

            $this->commandBus->dispatch(new CreateCampaign($id, $data->toArray()));

            return $this->viewHandler->handle(FosView::create(['campaignId' => (string) $id]));
        }

        return $this->viewHandler->handle(FosView::create($form->getErrors(), Response::HTTP_BAD_REQUEST));
    }

    /**
     * Add Brand Icon to campaign.
     *
     * @Route(name="oloy.campaign.add_brand_icon", path="/campaign/{campaign}/brand_icon")
     * @Method("POST")
     * @Security("is_granted('EDIT', campaign)")
     * @ApiDoc(
     *     name="Add brand icon to Campaign",
     *     section="Campaign",
     *     input={"class" = "OpenLoyalty\Bundle\CampaignBundle\Form\Type\CampaignBrandIconFormType", "name" = "icon"}
     * )
     *
     * @View(serializerGroups={"admin", "Default"})
     *
     * @param Request        $request
     * @param DomainCampaign $campaign
     *
     * @return FosView
     */
    public function addBrandIconAction(Request $request, DomainCampaign $campaign): FosView
    {
        $form = $this->formFactory->createNamed('brand_icon', CampaignBrandIconFormType::class);
        $form->handleRequest($request);

        if ($form->isValid()) {
            /** @var UploadedFile $file */
            $file = $form->getData()->getFile();
            $this->campaignBrandIconUploader->remove($campaign->getCampaignBrandIcon());
            $icon = $this->campaignBrandIconUploader->upload($file);
            $command = new SetCampaignBrandIcon($campaign->getCampaignId(), $icon);
            $this->commandBus->dispatch($command);

            return $this->view([], Response::HTTP_OK);
        }

        return $this->view($form->getErrors(), Response::HTTP_BAD_REQUEST);
    }

    /**
     * Add photo to campaign.
     *
     * @Route(name="oloy.campaign.add_photo", path="/campaign/{campaign}/photo")
     * @Method("POST")
     * @Security("is_granted('EDIT', campaign)")
     * @ApiDoc(
     *     name="Add photo to Campaign",
     *     section="Campaign",
     *     input={"class" = "OpenLoyalty\Bundle\CampaignBundle\Form\Type\CampaignPhotoFormType", "name" = "photo"}
     * )
     *
     * @View(serializerGroups={"admin", "Default"})
     *
     * @param Request        $request
     * @param DomainCampaign $campaign
     *
     * @return FosView
     */
    public function addPhotoAction(Request $request, DomainCampaign $campaign): FosView
    {
        $form = $this->formFactory->createNamed('photo', CampaignPhotoFormType::class);
        $form->handleRequest($request);

        if ($form->isValid()) {
            /** @var UploadedFile $file */
            $file = $form->getData()->getFile();
            $this->campaignPhotoUploader->remove($campaign->getCampaignPhoto());
            $photo = $this->campaignPhotoUploader->upload($file);
            $command = new SetCampaignPhoto($campaign->getCampaignId(), $photo);
            $this->commandBus->dispatch($command);

            return $this->view([], Response::HTTP_OK);
        }

        return $this->view($form->getErrors(), Response::HTTP_BAD_REQUEST);
    }

    /**
     * Remove brand icon from campaign.
     *
     * @Route(name="oloy.campaign.remove_brand_icon", path="/campaign/{campaign}/brand_icon")
     * @Method("DELETE")
     * @Security("is_granted('EDIT', campaign)")
     * @ApiDoc(
     *     name="Delete brand icon from Campaign",
     *     section="Campaign"
     * )
     *
     * @View(serializerGroups={"admin", "Default"})
     *
     * @param DomainCampaign $campaign
     *
     * @return FosView
     */
    public function removeBrandIconAction(DomainCampaign $campaign): FosView
    {
        $this->campaignBrandIconUploader->remove($campaign->getCampaignBrandIcon());

        $command = new RemoveCampaignBrandIcon($campaign->getCampaignId());
        $this->commandBus->dispatch($command);

        return $this->view([], Response::HTTP_OK);
    }

    /**
     * Get campaign brand icon.
     *
     * @Route(name="oloy.campaign.get_brand_icon", path="/campaign/{campaign}/brand_icon")
     * @Method("GET")
     * @ApiDoc(
     *     name="Get campaign brand icon",
     *     section="Campaign"
     * )
     *
     * @View(serializerGroups={"admin", "Default"})
     *
     * @param DomainCampaign $campaign
     *
     * @return Response
     */
    public function getBrandIconAction(DomainCampaign $campaign): Response
    {
        $photo = $campaign->getCampaignBrandIcon();

        return $this->getCampaignFileResponse($this->campaignBrandIconUploader, $photo);
    }

    /**
     * @param CampaignFileUploader|null $uploader
     * @param CampaignFile|null         $file
     *
     * @return Response
     */
    protected function getCampaignFileResponse(?CampaignFileUploader $uploader, ?CampaignFile $file): Response
    {
        if (!$file) {
            throw $this->createNotFoundException();
        }
        $content = $uploader->get($file);
        if (!$content) {
            throw $this->createNotFoundException();
        }

        $response = new Response($content);
        $response->headers->set('Content-Disposition', 'inline');
        $response->headers->set('Content-Type', $file->getMime());
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');

        return $response;
    }

    /**
     * Remove photo from campaign.
     *
     * @Route(name="oloy.campaign.remove_photo", path="/campaign/{campaign}/photo")
     * @Method("DELETE")
     * @Security("is_granted('EDIT', campaign)")
     * @ApiDoc(
     *     name="Delete photo from Campaign",
     *     section="Campaign"
     * )
     *
     * @View(serializerGroups={"admin", "Default"})
     *
     * @param DomainCampaign $campaign
     *
     * @return FosView
     */
    public function removePhotoAction(DomainCampaign $campaign): FosView
    {
        $this->campaignPhotoUploader->remove($campaign->getCampaignPhoto());

        $command = new RemoveCampaignPhoto($campaign->getCampaignId());
        $this->commandBus->dispatch($command);

        return $this->view([], Response::HTTP_OK);
    }

    /**
     * Get campaign photo.
     *
     * @Route(name="oloy.campaign.get_photo", path="/campaign/{campaign}/photo")
     * @Method("GET")
     * @ApiDoc(
     *     name="Get campaign photo",
     *     section="Campaign"
     * )
     *
     * @View(serializerGroups={"admin", "Default"})
     *
     * @param DomainCampaign $campaign
     *
     * @return Response
     */
    public function getPhotoAction(DomainCampaign $campaign): Response
    {
        $photo = $campaign->getCampaignPhoto();

        return $this->getCampaignFileResponse($this->campaignPhotoUploader, $photo);
    }

    /**
     * Edit campaign.
     *
     * @Route(name="oloy.campaign.edit", path="/campaign/{campaign}")
     * @Method("PUT")
     * @Security("is_granted('EDIT', campaign)")
     * @ApiDoc(
     *     name="Create new Campaign",
     *     section="Campaign",
     *     input={"class" = "OpenLoyalty\Bundle\CampaignBundle\Form\Type\EditCampaignFormType", "name" = "campaign"},
     *     statusCodes={
     *       200="Returned when successful",
     *       400="Returned when there are errors in form",
     *       404="Returned when campaign not found"
     *     }
     * )
     *
     * @View(serializerGroups={"admin", "Default"})
     *
     * @param Request        $request
     * @param DomainCampaign $campaign
     *
     * @return Response
     */
    public function editAction(Request $request, DomainCampaign $campaign): Response
    {
        $form = $this->formFactory->createNamed('campaign', EditCampaignFormType::class, null, [
            'method' => 'PUT',
        ]);

        $form->handleRequest($request);

        if ($form->isValid()) {
            /** @var Campaign $data */
            $data = $form->getData();

            $this->commandBus->dispatch(new UpdateCampaign($campaign->getCampaignId(), $data->toArray()));

            return $this->viewHandler->handle(FosView::create(['campaignId' => $campaign->getCampaignId()->__toString()]));
        }

        return $this->viewHandler->handle(FosView::create($form->getErrors(), Response::HTTP_BAD_REQUEST));
    }

    /**
     * Change campaign state action to active or inactive.
     *
     * @Route(name="oloy.campaign.change_state", path="/campaign/{campaign}/{active}", requirements={"active":"active|inactive"})
     * @Method("POST")
     * @Security("is_granted('EDIT', campaign)")
     * @ApiDoc(
     *     name="Change Campaign state",
     *     section="Campaign"
     * )
     *
     * @View(serializerGroups={"admin", "Default"})
     *
     * @param DomainCampaign $campaign
     * @param                $active
     *
     * @return FosView
     */
    public function changeStateAction(DomainCampaign $campaign, $active): FosView
    {
        if ('active' === $active) {
            $campaign->setActive(true);
        } elseif ('inactive' === $active) {
            $campaign->setActive(false);
        }

        $this->commandBus->dispatch(
            new ChangeCampaignState($campaign->getCampaignId(), $campaign->isActive())
        );

        return $this->view(['campaignId' => $campaign->getCampaignId()->__toString()]);
    }

    /**
     * Get all campaigns.
     *
     * @Route(name="oloy.campaign.list", path="/campaign")
     * @Security("is_granted('LIST_ALL_CAMPAIGNS')")
     * @Method("GET")
     *
     * @ApiDoc(
     *     name="get campaigns list",
     *     section="Campaign",
     *     parameters={
     *          {"name"="active", "dataType"="boolean", "required"=false, "description"="Filter by activity"},
     *          {"name"="isPublic", "dataType"="boolean", "required"=false, "description"="Filter by public flag"},
     *          {"name"="isFeatured", "dataType"="boolean", "required"=false, "description"="Filter by featured tag"},
     *          {"name"="campaignType", "dataType"="string", "required"=false, "description"="Filter by campaign type"},
     *          {"name"="name", "dataType"="string", "required"=false, "description"="Filter by campaign name"},
     *          {"name"="page", "dataType"="integer", "required"=false, "description"="Page number"},
     *          {"name"="perPage", "dataType"="integer", "required"=false, "description"="Number of elements per page"},
     *          {"name"="sort", "dataType"="string", "required"=false, "description"="Field to sort by"},
     *          {"name"="direction", "dataType"="asc|desc", "required"=false, "description"="Sorting direction"},
     *          {"name"="categoryId[]", "dataType"="string", "required"=false, "description"="Filter by categories"},
     *     }
     * )
     *
     * @QueryParam(name="labels", nullable=true, description="filter by labels"))
     * @QueryParam(name="active", nullable=true, description="filter by activity"))
     * @QueryParam(name="isPublic", nullable=true, description="filter by public flag"))
     * @QueryParam(name="isFeatured", nullable=true, description="filter by featured tag"))
     * @QueryParam(name="campaignType", nullable=true, description="filter by campaign type"))
     * @QueryParam(name="name", nullable=true, description="filter by campaign name"))
     * @QueryParam(name="categoryId", nullable=true, description="filter by categories"))
     *
     * @View(serializerGroups={"admin", "Default"})
     *
     * @param Request $request
     *
     * @return FosView
     *
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     * @throws \Doctrine\ORM\ORMException
     */
    public function getListAction(Request $request): FosView
    {
        $paginator = $this->paginator->handleFromRequest($request);

        $params = $this->paramFetcher->all();

        $campaigns = $this->campaignRepository
            ->findByParametersPaginated(
                $params,
                $paginator->getPage(),
                $paginator->getPerPage(),
                $paginator->getSort(),
                $paginator->getSortDirection()
            );

        $total = $this->campaignRepository->countFindByParameters($params);

        $view = $this->view(
            [
                'campaigns' => $campaigns,
                'total' => $total,
            ],
            Response::HTTP_OK
        );

        $context = new Context();
        $context->setGroups(['Default', 'list']);
        $context->setAttribute(
            FOSContextProvider::OUTPUT_FORMAT_ATTRIBUTE_NAME,
            $request->get('format')
        );
        $view->setContext($context);

        return $view;
    }

    /**
     * Get all bought campaigns.
     *
     * @Route(name="oloy.campaign.bought.list", path="/campaign/bought")
     * @Security("is_granted('LIST_ALL_BOUGHT_CAMPAIGNS')")
     * @Method("GET")
     *
     * @ApiDoc(
     *     name="get bought campaigns list",
     *     section="Campaign",
     *     parameters={
     *      {"name"="page", "dataType"="integer", "required"=false, "description"="Page number"},
     *      {"name"="perPage", "dataType"="integer", "required"=false, "description"="Number of elements per page"},
     *      {"name"="sort", "dataType"="string", "required"=false, "description"="Field to sort by"},
     *      {"name"="direction", "dataType"="asc|desc", "required"=false, "description"="Sorting direction"},
     *      {"name"="purchasedAtFrom", "dataType"="string", "required"=false, "description"="Purchased date from filter"},
     *      {"name"="purchasedAtTo", "dataType"="string", "required"=false, "description"="Purchased date to filter"},
     *      {"name"="activeSinceFrom", "dataType"="string", "required"=false, "description"="Active since date from filter"},
     *      {"name"="activeSinceTo", "dataType"="string", "required"=false, "description"="Active since date to filter"},
     *      {"name"="activeToFrom", "dataType"="string", "required"=false, "description"="Active to date from filter"},
     *      {"name"="activeToTo", "dataType"="string", "required"=false, "description"="Active to date to filter"},
     *     }
     * )
     *
     * @QueryParam(name="used", nullable=true, description="Used"))
     * @QueryParam(name="purchasedAtFrom", nullable=true, description="Range date filter"))
     * @QueryParam(name="purchasedAtTo", nullable=true, description="Range date filter"))
     * @QueryParam(name="activeSinceFrom", nullable=true, description="Range date filter"))
     * @QueryParam(name="activeSinceTo", nullable=true, description="Range date filter"))
     * @QueryParam(name="activeToFrom", nullable=true, description="Range date filter"))
     * @QueryParam(name="activeToTo", nullable=true, description="Range date filter"))
     *
     * @param Request $request
     *
     * @return FosView
     */
    public function getBoughtListAction(Request $request): FosView
    {
        $paginator = $this->paginator->handleFromRequest($request);
        $params = $this->paramManager->stripNulls($this->paramFetcher->all());
        // extract ES-like params for date range filter
        $this->paramManager->appendDateRangeFilter(
            $params,
            'purchasedAt',
            $params['purchasedAtFrom'] ?? null,
            $params['purchasedAtTo'] ?? null
        );
        $this->paramManager->appendDateRangeFilter(
            $params,
            'activeSince',
            $params['activeSinceFrom'] ?? null,
            $params['activeSinceTo'] ?? null
        );
        $this->paramManager->appendDateRangeFilter(
                $params,
                'activeSince',
                $params['activeToFrom'] ?? null,
                $params['activeToTo'] ?? null
            );

        unset($params['purchasedAtFrom']);
        unset($params['purchasedAtTo']);
        unset($params['activeSinceFrom']);
        unset($params['activeSinceTo']);
        unset($params['activeToFrom']);
        unset($params['activeToTo']);

        $boughtCampaigns = $this->campaignBoughtRepository->findByParametersPaginated(
            $params,
            true,
            $paginator->getPage(),
            $paginator->getPerPage(),
            $paginator->getSort(),
            $paginator->getSortDirection()
        );

        $total = $this->campaignBoughtRepository->countTotal($params);

        return $this->view(
            [
                'boughtCampaigns' => $boughtCampaigns,
                'total' => $total,
            ]
        );
    }

    /**
     * @Route(name="oloy.campaign.bought.csv", path="/campaign/bought/export/csv")
     * @Security("is_granted('LIST_ALL_BOUGHT_CAMPAIGNS')")
     * @Method("GET")
     *
     * @ApiDoc(
     *     name="generate CSV of bought campaigns",
     *     section="Campaign",
     *     parameters={
     *      {"name"="purchasedAtFrom", "dataType"="string", "required"=false, "description"="Purchased date from filter"},
     *      {"name"="purchasedAtTo", "dataType"="string", "required"=false, "description"="Purchased date to filter"},
     *     }
     * )
     * @QueryParam(
     *     name="purchasedAtFrom", nullable=true, description="Range date filter"))
     * @QueryParam(name="purchasedAtTo", nullable=true, description="Range date filter"))
     *
     * @return BinaryFileResponse|FosView
     */
    public function exportBoughtAction()
    {
        $params = $this->paramManager->stripNulls($this->paramFetcher->all());
        $headers = $this->getParameter('oloy.campaign.bought.export.headers');
        $fields = $this->getParameter('oloy.campaign.bought.export.fields');

        try {
            // extract ES-like params for date range filter
            $this->paramManager
                ->appendDateRangeFilter(
                    $params,
                    'purchasedAt',
                    $params['purchasedAtFrom'] ?? null,
                    $params['purchasedAtTo'] ?? null
                );

            unset($params['purchasedAtFrom']);
            unset($params['purchasedAtTo']);
            $content = $this->csvGenerator->generate($this->campaignBoughtRepository->findByParameters($params), $headers, $fields);
            $handle = tmpfile();
            fwrite($handle, $content);
            $file = new File(stream_get_meta_data($handle)['uri'], false);
            $file = $file->move($this->container->getParameter('kernel.project_dir').'/app/uploads');
            $response = new BinaryFileResponse($file);
            $response->deleteFileAfterSend(true);
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT);

            return $response;
        } catch (\Exception $exception) {
            return $this->view($this->translator->trans($exception->getMessage()), Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Get all visible campaigns.
     *
     * @Route(name="oloy.campaign.seller.list", path="/seller/campaign")
     * @Security("is_granted('LIST_ALL_VISIBLE_CAMPAIGNS')")
     * @Method("GET")
     *
     * @ApiDoc(
     *     name="get campaigns list",
     *     section="Campaign",
     *     parameters={
     *      {"name"="isPublic", "dataType"="boolean", "required"=false, "description"="Filter by public flag"},
     *      {"name"="page", "dataType"="integer", "required"=false, "description"="Page number"},
     *      {"name"="perPage", "dataType"="integer", "required"=false, "description"="Number of elements per page"},
     *      {"name"="sort", "dataType"="string", "required"=false, "description"="Field to sort by"},
     *      {"name"="direction", "dataType"="asc|desc", "required"=false, "description"="Sorting direction"},
     *     }
     * )
     *
     * @View(serializerGroups={"admin", "Default"})
     *
     * @param Request $request
     *
     * @return FosView
     *
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function getVisibleListAction(Request $request): FosView
    {
        $paginator = $this->paginator->handleFromRequest($request);

        $campaigns = $this->campaignRepository
            ->findAllVisiblePaginated(
                $paginator->getPage(),
                $paginator->getPerPage(),
                $paginator->getSort(),
                $paginator->getSortDirection(),
                [
                    'isPublic' => $request->query->get('isPublic'),
                ]
            );

        $total = $this->campaignRepository->countTotal(
            true,
            [
                'isPublic' => $request->query->get('isPublic'),
            ]
        );

        $view = $this->view(
            [
                'campaigns' => $campaigns,
                'total' => $total,
            ],
            Response::HTTP_OK
        );

        $context = new Context();
        $context->setGroups(['Default', 'list']);
        $context->setAttribute(
            FOSContextProvider::OUTPUT_FORMAT_ATTRIBUTE_NAME,
            $request->get('format')
        );
        $view->setContext($context);

        return $view;
    }

    /**
     * Get active campaigns.
     *
     * @Route(name="oloy.campaign.active.get", path="/campaign/active")
     * @Method("GET")
     * @Security("is_granted('LIST_ALL_ACTIVE_CAMPAIGNS')")
     *
     * @ApiDoc(
     *     name="Get active campaigns",
     *     section="Campaign"
     * )
     *
     * @param Request $request
     *
     * @return FosView
     */
    public function getActiveCampaignsAction(Request $request): FosView
    {
        $domainCampaigns = $this->campaignRepository->getActiveCampaigns();

        $activeCampaigns = new ActiveCampaigns();

        /** @var Campaign $campaign */
        foreach ($domainCampaigns as $campaign) {
            $campaignShortInfo = new CampaignShortInfo($campaign);
            $activeCampaigns->addCampaign($campaignShortInfo);
        }

        $view = $this->view(
            [
                'campaigns' => $activeCampaigns->getCampaigns(),
            ],
            Response::HTTP_OK
        );

        $context = new Context();
        $context->setGroups(['Default', 'list']);
        $context->setAttribute(
            FOSContextProvider::OUTPUT_FORMAT_ATTRIBUTE_NAME,
            $request->get('format')
        );
        $view->setContext($context);

        return $view;
    }

    /**
     * Get single campaign details.
     *
     * @Route(name="oloy.campaign.get", path="/campaign/{campaign}")
     * @Route(name="oloy.campaign.seller.get", path="/seller/campaign/{campaign}")
     *
     * @Method("GET")
     * @Security("is_granted('VIEW', campaign)")
     * @ApiDoc(
     *     name="get campaign details",
     *     section="Campaign"
     * )
     *
     * @View(serializerGroups={"admin", "Default"})
     *
     * @param Request        $request
     * @param DomainCampaign $campaign
     *
     * @return FosView
     */
    public function getAction(Request $request, DomainCampaign $campaign): FosView
    {
        $view = $this->view($campaign);
        $view->getContext()->setAttribute(
            FOSContextProvider::OUTPUT_FORMAT_ATTRIBUTE_NAME,
            $request->get('format')
        );

        return $view;
    }

    /**
     * Get customers who for whom this campaign is visible.
     *
     * @Route(name="oloy.campaign.get_customers_visible_for_campaign", path="/campaign/{campaign}/customers/visible")
     * @Method("GET")
     * @Security("is_granted('LIST_ALL_CAMPAIGNS')")
     *
     * @ApiDoc(
     *     name="campaign visible for customers",
     *     section="Campaign",
     *     parameters={
     *      {"name"="page", "dataType"="integer", "required"=false, "description"="Page number"},
     *      {"name"="perPage", "dataType"="integer", "required"=false, "description"="Number of elements per page"},
     *      {"name"="sort", "dataType"="string", "required"=false, "description"="Field to sort by"},
     *      {"name"="direction", "dataType"="asc|desc", "required"=false, "description"="Sorting direction"},
     *     }
     * )
     *
     * @View(serializerGroups={"admin", "Default"})
     *
     * @param Request        $request
     * @param DomainCampaign $campaign
     *
     * @return FosView
     */
    public function getVisibleForCustomersAction(Request $request, DomainCampaign $campaign): FosView
    {
        $paginator = $this->paginator->handleFromRequest($request);

        $customers = array_values($this->campaignProvider->visibleForCustomers($campaign));

        $res = [];
        foreach ($customers as $id) {
            $tmp = $this->customerDetailsRepository->find($id);
            if ($tmp instanceof CustomerDetails) {
                $res[] = $tmp;
            }
        }
        $total = count($res);
        $res = array_slice($res, ($paginator->getPage() - 1) * $paginator->getPerPage(), $paginator->getPerPage());

        return $this->view([
            'customers' => $res,
            'total' => $total,
        ]);
    }

    /**
     * List all campaigns that can be bought by this customer.
     *
     * @Route(name="oloy.campaign.admin.customer.available", path="/admin/customer/{customer}/campaign/available")
     * @Route(name="oloy.campaign.seller.customer.available", path="/seller/customer/{customer}/campaign/available")
     * @Method("GET")
     * @Security("is_granted('BUY_FOR_CUSTOMER_SELLER') or is_granted('BUY_FOR_CUSTOMER_ADMIN')")
     *
     * @ApiDoc(
     *     name="get available campaigns for customer list",
     *     section="Campaign",
     *     parameters={
     *          {"name"="isFeatured", "dataType"="boolean", "required"=false, "description"="Filter by featured tag"},
     *          {"name"="isPublic", "dataType"="boolean", "required"=false, "description"="Filter by public flag"},
     *          {"name"="hasSegment", "dataType"="boolean", "required"=false, "description"="Whether campaign is offered exclusively to some segments"},
     *          {"name"="page", "dataType"="integer", "required"=false, "description"="Page number"},
     *          {"name"="perPage", "dataType"="integer", "required"=false, "description"="Number of elements per page"},
     *          {"name"="sort", "dataType"="string", "required"=false, "description"="Field to sort by"},
     *          {"name"="direction", "dataType"="asc|desc", "required"=false, "description"="Sorting direction"},
     *          {"name"="categoryId[]", "dataType"="string", "required"=false, "description"="Filter by categories"},
     *     }
     * )
     *
     * @View(serializerGroups={"admin", "Default"})
     *
     * @param Request         $request
     * @param CustomerDetails $customer
     *
     * @return FosView
     */
    public function availableCampaigns(Request $request, CustomerDetails $customer): FosView
    {
        $paginator = $this->paginator->handleFromRequest($request);

        $categoryIds = $request->query->get('categoryId', []);
        $customerSegments = $this->segmentedCustomersRepository
            ->findBy(['customerId' => (string) $customer->getCustomerId()]);
        $segments = array_map(
            function (SegmentedCustomers $segmentedCustomers) {
                return new SegmentId((string) $segmentedCustomers->getSegmentId());
            },
            $customerSegments
        );

        $campaigns = $this->campaignRepository
            ->getVisibleCampaignsForLevelAndSegment(
                $segments,
                $customer->getLevelId() ? new LevelId($customer->getLevelId()->__toString()) : null,
                $categoryIds,
                null,
                null,
                $paginator->getSort(),
                $paginator->getSortDirection(),
                [
                    'featured' => $request->query->get('isFeatured'),
                    'isPublic' => $request->query->get('isPublic'),
                ]
            );

        // filter by segment exclusiveness
        $mustHaveSegments = $request->query->get('hasSegment', null);
        if (null !== $mustHaveSegments) {
            $campaigns = array_filter($campaigns, function (DomainCampaign $campaign) use ($mustHaveSegments) {
                return $mustHaveSegments ? $campaign->hasSegments() : !$campaign->hasSegments();
            });
        }

        // filter by usage left
        $campaigns = array_filter($campaigns, function (DomainCampaign $campaign) use ($customer) {
            $usageLeft = $this->campaignProvider->getUsageLeft($campaign);
            $usageLeftForCustomer = $this->campaignProvider
                ->getUsageLeftForCustomer($campaign, (string) $customer->getCustomerId());

            return $usageLeft > 0 && $usageLeftForCustomer > 0;
        });

        $view = $this->view(
            [
                'campaigns' => array_slice(
                    $campaigns,
                    ($paginator->getPage() - 1) * $paginator->getPerPage(),
                    $paginator->getPerPage()
                ),
                'total' => count($campaigns),
            ],
            Response::HTTP_OK
        );

        $context = new Context();
        $context->setGroups(['Default']);
        $context->setAttribute('customerId', $customer->getCustomerId()->__toString());
        $context->setAttribute(
            FOSContextProvider::OUTPUT_FORMAT_ATTRIBUTE_NAME,
            $request->get('format')
        );
        $view->setContext($context);

        return $view;
    }

    /**
     * Buy campaign as seller for customer.
     *
     * @Route(name="oloy.campaign.seller.buy", path="/seller/customer/{customer}/campaign/{campaign}/buy")
     * @Method("POST")
     * @Security("is_granted('BUY_FOR_CUSTOMER_SELLER')")
     *
     * @ApiDoc(
     *     name="buy campaign for customer",
     *     section="Campaign",
     *     statusCodes={
     *       200="Returned when successful",
     *       400="With error 'No coupons left' returned when campaign cannot be bought because of lack of coupons. With error 'Not enough points' returned when campaign cannot be bought because of not enough points on customer account. With empty error returned when campaign limits exceeded."
     *     }
     * )
     *
     * @View(serializerGroups={"admin", "Default"})
     *
     * @param Request         $request
     * @param DomainCampaign  $campaign
     * @param CustomerDetails $customer
     *
     * @return FosView
     */
    public function buyCampaign(Request $request, DomainCampaign $campaign, CustomerDetails $customer): FosView
    {
        if (!$this->campaignValidator->isCampaignActive($campaign) ||
            !$this->campaignValidator->isCampaignVisible($campaign)
        ) {
            throw $this->createNotFoundException();
        }

        $quantity = 1;
        if (!$campaign->isPercentageDiscountCode() && !$campaign->isCashback()) {
            $quantity = $request->get('quantity', 1);
        }

        $coupons = [];
        try {
            $this->campaignValidator->validateCampaignLimits(
                $campaign,
                new CustomerId($customer->getCustomerId()->__toString()),
                $quantity
            );
            $this->campaignValidator->checkIfCustomerStatusIsAllowed($customer->getStatus());
            $this->campaignValidator->checkIfCustomerHasEnoughPoints(
                $campaign,
                new CustomerId($customer->getCustomerId()->__toString()),
                $quantity
            );

            for ($i = 0; $i < $quantity; ++$i) {
                $coupon = $this->couponCodeProvider->getCoupon($campaign);
                $this->commandBus->dispatch(
                    new BuyCampaign(
                        $campaign->getCampaignId(),
                        new CustomerId($customer->getId()),
                        $coupon
                    )
                );

                $this->customerEmailProvider->customerBoughtCampaign(
                    $customer,
                    $campaign,
                    $coupon
                );
                $coupons[] = $coupon;
            }

            return $this->view(['coupons' => $coupons]);
        } catch (CampaignLimitException | NoCouponsLeftException | NotEnoughPointsException | NotAllowedException $e) {
            return $this->view(['error' => $this->translator->trans($e->getMessage())], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Buy campaign for customer as admin.
     *
     * @Route(name="oloy.campaign.buy", path="/admin/customer/{customer}/campaign/{campaign}/buy")
     * @Method("POST")
     * @Security("is_granted('BUY_FOR_CUSTOMER_ADMIN')")
     *
     * @ApiDoc(
     *     name="buy campaign for customer",
     *     section="Campaign",
     *     parameters={
     *      {"name"="withoutPoints", "dataType"="boolean", "required"=false},
     *      {"name"="quantity", "dataType"="integer", "required"=false}
     *     },
     *     statusCodes={
     *       200="Returned when successful",
     *       400="With error 'No coupons left' returned when campaign cannot be bought because of lack of coupons. With error 'Not enough points' returned when campaign cannot be bought because of not enough points on customer account. With empty error returned when campaign limits exceeded."
     *     }
     * )
     *
     * @View(serializerGroups={"admin", "Default"})
     *
     * @param DomainCampaign  $campaign
     * @param CustomerDetails $customer
     * @param Request         $request
     *
     * @return FosView
     *
     * @throws \Exception
     * @throws NotFoundHttpException
     */
    public function buyCampaignAdmin(DomainCampaign $campaign, CustomerDetails $customer, Request $request): FosView
    {
        if (!$campaign->canBeBoughtManually()) {
            throw new BadRequestHttpException();
        }

        $withoutPoints = $request->get('withoutPoints', false);
        $transactionId = $request->get('transactionId', null);

        if (!$this->campaignValidator->isCampaignActive($campaign)) {
            throw $this->createNotFoundException();
        }
        $quantity = 1;
        if (!$campaign->isPercentageDiscountCode() && !$campaign->isCashback()) {
            $quantity = $request->get('quantity', 1);
        }
        $coupons = [];
        try {
            if ($transactionId) {
                $transaction = $this->transactionDetailsRepository->find($transactionId);
                if ($transaction) {
                    $transactionValue = $transaction->getGrossValue();
                    if ($transaction->getCustomerId()->__toString() != $customer->getId()) {
                        throw new InvalidTransactionException();
                    }
                }
            }

            if (!isset($transactionValue) && $campaign->isTransactionRequired()) {
                throw new TransactionRequiredException();
            }

            $this->campaignValidator->validateCampaignLimits(
                $campaign,
                new CustomerId($customer->getCustomerId()->__toString()),
                $quantity
            );
            $this->campaignValidator->checkIfCustomerStatusIsAllowed($customer->getStatus());
            if (!$withoutPoints) {
                $this->campaignValidator->checkIfCustomerHasEnoughPoints(
                    $campaign,
                    new CustomerId($customer->getCustomerId()->__toString()),
                    $quantity
                );
            }

            for ($i = 0; $i < $quantity; ++$i) {
                $coupon = $this->couponCodeProvider->getCoupon($campaign, $transactionValue ?? 0);
                $this->commandBus->dispatch(
                    new BuyCampaign(
                        $campaign->getCampaignId(),
                        new CustomerId($customer->getId()),
                        $coupon,
                        $withoutPoints === true ? 0 : $campaign->getCostInPoints(),
                        $transactionId ? new TransactionId($transactionId) : null
                    )
                );

                $this->customerEmailProvider->customerBoughtCampaign(
                    $customer,
                    $campaign,
                    $coupon
                );
                $coupons[] = $coupon;
            }
        } catch (CampaignLimitException | NotAllowedException | NotEnoughPointsException | NoCouponsLeftException $e) {
            return $this->view(['error' => $this->translator->trans($e->getMessage())], Response::HTTP_BAD_REQUEST);
        }

        return $this->view(['coupons' => $coupons]);
    }

    /**
     * Get all campaigns bought by customer.
     *
     * @Route(name="oloy.campaign.admin.customer.bought", path="/admin/customer/{customer}/campaign/bought")
     * @Route(name="oloy.campaign.seller.customer.bought", path="/seller/customer/{customer}/campaign/bought")
     * @Method("GET")
     * @Security("is_granted('BUY_FOR_CUSTOMER_SELLER') or is_granted('BUY_FOR_CUSTOMER_ADMIN')")
     *
     * @ApiDoc(
     *     name="get customer bough campaigns list",
     *     section="Customer Campaign",
     *     parameters={
     *          {"name"="includeDetails", "dataType"="boolean", "required"=false},
     *          {"name"="page", "dataType"="integer", "required"=false, "description"="Page number"},
     *          {"name"="perPage", "dataType"="integer", "required"=false, "description"="Number of elements per page"},
     *          {"name"="sort", "dataType"="string", "required"=false, "description"="Field to sort by"},
     *          {"name"="direction", "dataType"="asc|desc", "required"=false, "description"="Sorting direction"},
     *     }
     * )
     *
     * @View(serializerGroups={"admin", "Default"})
     *
     * @param Request         $request
     * @param CustomerDetails $customer
     *
     * @return FosView
     */
    public function boughtCampaigns(Request $request, CustomerDetails $customer): FosView
    {
        $paginator = $this->paginator->handleFromRequest($request);

        if (count($customer->getCampaignPurchases()) == 0) {
            return $this->view(
                [
                    'campaigns' => [],
                    'total' => 0,
                ],
                Response::HTTP_OK
            );
        }

        $campaigns = $this->customerDetailsRepository
            ->findPurchasesByCustomerIdPaginated(
                $customer->getCustomerId(),
                $paginator->getPage(),
                $paginator->getPerPage(),
                $paginator->getSort(),
                $paginator->getSortDirection(),
                true
            );

        if ($request->get('includeDetails', false)) {
            $campaigns = array_map(function (CampaignPurchase $campaignPurchase) {
                $campaignPurchase->setCampaign($this->campaignRepository->byId(new CampaignId($campaignPurchase->getCampaignId()->__toString())));

                return $campaignPurchase;
            }, $campaigns);
        }

        return $this->view(
            [
                'campaigns' => $campaigns,
                'total' => $this->customerDetailsRepository->countPurchasesByCustomerId($customer->getCustomerId(), true),
            ],
            Response::HTTP_OK
        );
    }

    /**
     * Mark specific coupon as used/unused by customer.
     *
     * @Route(name="oloy.campaign.admin.customer.coupon_usage", path="/admin/customer/{customer}/campaign/{campaign}/coupon/{coupon}")
     * @Method("POST")
     *
     * @ApiDoc(
     *     name="mark coupon as used",
     *     section="Customer Campaign",
     *     parameters={
     *      {"name"="used", "dataType"="true|false", "required"=true, "description"="True if mark as used, false otherwise"},
     *     },
     *     statusCodes={
     *       200="Returned when successful",
     *       400="Returned when parameter 'used' not provided",
     *       404="Returned when customer or campaign not found"
     *     }
     * )
     *
     * @View(serializerGroups={"admin", "Default"})
     *
     * @param Request         $request
     * @param CustomerDetails $customer
     * @param DomainCampaign  $campaign
     * @param string          $coupon
     *
     * @return FosView
     */
    public function campaignCouponUsage(Request $request, CustomerDetails $customer, DomainCampaign $campaign, $coupon): FosView
    {
        $used = $request->request->get('used', null);
        if ($used === null) {
            return $this->view(
                ['errors' => $this->translator->trans('campaign.field_used_is_required')],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (is_string($used)) {
            $used = str_replace('"', '', $used);
            $used = str_replace("'", '', $used);
        }

        if ($used === 'false' || $used === '0' || $used === 0) {
            $used = false;
        }

        try {
            $this->multipleCampaignCouponUsageProvider->validateRequestForCustomer(
                [
                    [
                        'code' => $coupon,
                        'campaignId' => (string) $campaign->getCampaignId(),
                        'used' => $used,
                    ],
                ],
                $customer
            );
        } catch (NoCouponsLeftException $e) {
            return $this->view(['error' => $this->translator->trans($e->getMessage())], Response::HTTP_BAD_REQUEST);
        } catch (CampaignUsageChangeException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $this->commandBus->dispatch(
            new ChangeCampaignUsage(
                $customer->getCustomerId(),
                new CustomerCampaignId($campaign->getCampaignId()->__toString()),
                new Coupon($coupon),
                $used
            )
        );

        return $this->view(['used' => $used]);
    }

    /**
     * Mark multiple coupons as used/unused by customer.
     *
     * @Route(name="oloy.campaign.admin.customer.coupon_multiple_usage", path="/admin/campaign/coupons/mark_as_used")
     * @Method("POST")
     * @Security("is_granted('MARK_MULTIPLE_COUPONS_AS_USED')")
     *
     * @ApiDoc(
     *     name="mark multiple coupons as used",
     *     section="Customer Campaign",
     *     parameters={
     *          {"name"="coupons[]", "dataType"="array", "required"=true, "description"="List of coupons to mark as used"},
     *          {"name"="coupons[][used]", "dataType"="boolean", "required"=true, "description"="If coupon is used or not"},
     *          {"name"="coupons[][campaignId]", "dataType"="string", "required"=true, "description"="CampaignId value"},
     *          {"name"="coupons[][customerId]", "dataType"="string", "required"=true, "description"="CustomerId value"},
     *          {"name"="coupons[][code]", "dataType"="string", "required"=true, "description"="Coupon code"},
     *     },
     *     statusCodes={
     *       200="Returned when successful",
     *       400="Returned when data is invalid",
     *       404="Returned when customer or campaign not found"
     *     }
     * )
     *
     * @View(serializerGroups={"admin", "Default"})
     *
     * @param Request $request
     *
     * @return FosView
     *
     * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
     * @throws BadRequestHttpException
     */
    public function campaignCouponListUsage(Request $request): FosView
    {
        $coupons = $request->request->get('coupons', []);

        if (empty($coupons)) {
            throw new BadRequestHttpException($this->translator->trans('campaign.invalid_data'));
        }

        try {
            $commands = $this->multipleCampaignCouponUsageProvider->validateRequest($coupons);
        } catch (NoCouponsLeftException $e) {
            return $this->view(['error' => $this->translator->trans($e->getMessage())], Response::HTTP_BAD_REQUEST);
        } catch (CampaignUsageChangeException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }
        $result = [];

        /** @var ChangeCampaignUsage $command */
        foreach ($commands as $command) {
            $this->commandBus->dispatch($command);

            $result[] = new CouponUsageResponse(
                $command->getCoupon()->getCode(),
                $command->isUsed(),
                $command->getCampaignId()->__toString(),
                $command->getCustomerId()->__toString()
            );
        }

        return $this->view(['coupons' => $result]);
    }

    /**
     * List only featured campaigns.
     *
     * @Route(name="oloy.campaign.public.featured", path="/campaign/public/featured")
     * @Method("GET")
     * @Security("is_granted('IS_AUTHENTICATED_ANONYMOUSLY')")
     *
     * @ApiDoc(
     *     name="list only featured campaigns",
     *     section="Campaign",
     *     parameters={
     *          {"name"="isPublic", "dataType"="boolean", "required"=false, "description"="Filter by public flag"}
     *     },
     *     statusCodes={
     *          200="Returned when successful",
     *          400="Returned when data is invalid",
     *          404="Returned when customer or campaign not found"
     *     }
     * )
     *
     * @View(serializerGroups={"Public"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function getPublicFeaturedAction(Request $request): Response
    {
        $paginator = $this->paginator->handleFromRequest($request);

        $campaigns = $this->campaignRepository->findAllFeaturedPaginated(
            $paginator->getPage(),
            $paginator->getPerPage(),
            $paginator->getSort(),
            $paginator->getSortDirection(),
            [
                'isPublic' => $request->query->get('isPublic'),
            ]
        );

        $total = $this->campaignRepository->countFeatured(
            [
                'isPublic' => $request->query->get('isPublic'),
            ]
        );

        $view = FosView::create(
            [
                'campaigns' => $campaigns,
                'total' => $total,
            ],
            Response::HTTP_OK
        );

        $context = new Context();
        $context->setGroups(['Public', 'list']);
        $context->setAttribute(
            FOSContextProvider::OUTPUT_FORMAT_ATTRIBUTE_NAME,
            $request->get('format')
        );

        $view->setContext($context);

        return $this->viewHandler->handle($view);
    }
}
