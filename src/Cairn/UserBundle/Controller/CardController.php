<?php

namespace Cairn\UserBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

//manage Events 
use Cairn\UserBundle\Event\SecurityEvents;
use Cairn\UserBundle\Event\InputCardKeyEvent;

//manage Entities
use Cairn\UserBundle\Entity\User;
use Cairn\UserBundle\Entity\Card;

//manage HTTP format
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

use Knp\Snappy\Pdf;

//manage Forms
use Cairn\UserBundle\Form\CardType;
use Cairn\UserBundle\Form\ConfirmationType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;                               
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;

use Cairn\UserBundle\Validator\UserPassword;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;


/**
 * This class contains all actions related to security cards
 *
 * @Security("is_granted('ROLE_ADHERENT')")
 */
class CardController extends Controller
{


    /**
     * The user must input a key of his card in order to keep browsing
     *
     * This action is used as a security layer to ensure user's identity. If the input is incorrect, user's attribute 'cardKeyTries'
     * is incremented. 3 failures leads to disable the user.
     *
     * This action is called only on sensible operations, whose routes and URLs(if route is not enough but the query must be considered)
     * are defined in the SecurityEvents class. 
     * Of course, the user's card must exist and be active, otherwise no sensible operation can be operated.
     *
     * For all sensible operations being listed in the SecurityEvent class, controller actions must contain query parameters in the query 
     * array and not in the route itself, as the query is passed as a query to this function before reaching the sensible 
     * initial route. Otherwise, for instance, if {id} was passed in the route, it wouldn't be available from here, and it would be 
     * impossible to reach the initial request.
     *
     * @see \Cairn\UserBundle\Event\SecurityEvents
     */
    public function inputCardKeyAction(Request $request)
    {
        $session = $request->getSession();
        $em = $this->getDoctrine()->getManager();

        $url = $request->query->get('url');
        $currentUser = $this->getUser();

        $card = $currentUser->getCard();

        if(!$card){
            $session->getFlashBag()->add('info','Vous n\'avez pas de carte de sécurité Cairn associée à votre espace membre. Votre opération ne peut être poursuivie. ');
            return $this->redirectToRoute('cairn_user_profile_view',array('id'=>$currentUser->getID()));
        }

        $positions = $card->generateCardPositions();
        if($request->isMethod('GET')){
            $session->set('position',$positions['index']);
        }
        $string_pos = $positions['cell'];

        $form = $this->createForm(CardType::class);

        if($request->isMethod('POST')){
            $form->handleRequest($request);
            if($form->isValid()){
                $position = $session->get('position');
                $cardKey =  $form->get('field')->getData();

                $event = new InputCardKeyEvent($currentUser,$cardKey,$position, $session);
                $this->get('event_dispatcher')->dispatch(SecurityEvents::INPUT_CARD_KEY,$event);

                if($event->getRedirect()){
                    $session->getFlashBag()->add('error','Votre compte a été bloqué');
                    return $this->redirectToRoute('fos_user_security_logout');
                }

                $nbTries = $currentUser->getCardKeyTries();
                if($nbTries == 0){
                    return new RedirectResponse($url);
                }
                else{
                    $session->getFlashBag()->add('error','Clé invalide. Veuillez réessayer');
                    return $this->redirectToRoute('cairn_user_card_security_layer',array('url'=>$url));
                }

            }
        }
        return $this->render('CairnUserBundle:Card:validate_card.html.twig',array('form'=>$form->createView(),'card'=>$card,'position'=>$string_pos));
    }


    /**
     * Index page for cards' action. 
     *
     * A user can make an action for its own card, or must be a referent of the card's owner
     *
     *@param User $user card owner
     *@Method("GET")
     */  
    public function cardOperationsAction(Request $request,User $user, $_format)
    {
        $currentUser = $this->getUser();

        if(! (($user === $currentUser) || ($user->hasReferent($currentUser))) ){
            throw new AccessDeniedException('Vous n\'êtes pas référent de '. $user->getUsername() .'. Vous ne pouvez donc pas poursuivre.');
        }

        if($_format == 'json'){
            return $this->json(array('user'=>$user));
        }
        return $this->render('CairnUserBundle:Card:card_operation.html.twig',array('user'=>$user));
    }

    public function orderCardAction(Request $request)
    {
        $session = $request->getSession();
        $messageNotificator = $this->get('cairn_user.message_notificator');

        $currentUser = $this->getUser();

        if($session->get('orderCard')){
            $session->getFlashBag()->add('info','La demande a déjà été effectuée.');
            return $this->redirectToRoute('cairn_user_profile_view',array('id'=>$currentUser->getID()));
        }

        if(!$currentUser->isAdherent()){
            $session->getFlashBag()->add('info','Action réservée aux adhérents.');
            return $this->redirectToRoute('cairn_user_profile_view',array('id'=>$currentUser->getID()));

        }

        if($currentUser->getCard()){
            $session->getFlashBag()->add('info','Vous avez déjà une carte de sécurité associée ');
            return $this->redirectToRoute('cairn_user_profile_view',array('id'=>$currentUser->getID()));
        }

        $subject = 'Envoi postal de carte de sécurité Cairn';
        $from = $this->getParameter('cairn_email_noreply');
        $to = $currentUser->getEmail();
        $body = $this->renderView('CairnUserBundle:Emails:request_card.html.twig', array('toAdmin'=>false));
        $messageNotificator->notifyByEmail($subject,$from,$to,$body);

        $to = $this->getParameter('cairn_email_management');
        $body = $this->renderView('CairnUserBundle:Emails:request_card.html.twig', array('toAdmin'=>true));
        $messageNotificator->notifyByEmail($subject,$from,$to,$body);

        $session->getFlashBag()->add('success','La demande a bien été enregistrée. L\'Association en a été informée. ');

        $session->set('orderCard',true);
        return $this->redirectToRoute('cairn_user_profile_view',array('id'=>$currentUser->getID()));

    }

    /**
     * Lists all the available cards
     *
     * This action lists all the available cards. The list can be filtered by creation date and by code
     *@Security("is_granted('ROLE_ADMIN')")
     */
    public function listAvailableCardsAction(Request $request)
    {
        $cardRepo = $this->getDoctrine()->getManager()->getRepository('CairnUserBundle:Card');

        $availableCards = $cardRepo->findAvailableCards();

        $beforeDefaultDate = new \Datetime(date('Y-m-d 23:59'));
        $afterDefaultDate = date_modify(new \Datetime(date('Y-m-d 23:59')), '- '.$this->getParameter('card_association_delay').' days');

        $form = $this->createFormBuilder()
            ->add('orderBy',   ChoiceType::class, array(
                'label' => 'affiché par',
                'choices' => array('dates de génération décroissantes'=>'DESC',
                                   'dates de génération croissantes' => 'ASC')))
            ->add('before',     DateTimeType::class, array(
                'label' => 'générées avant',
                'date_widget' => 'single_text',
                'time_widget' => 'single_text',
                'data'=> $beforeDefaultDate,
                ))
            ->add('after',     DateTimeType::class, array(
                'label' => 'générées après',
                'date_widget' => 'single_text',
                'time_widget' => 'single_text',
                'data'=> $afterDefaultDate,
                ))
            ->add('code',  TextType::class,array(
                'label'=>'Code',
                'required'=>false))
                ->add('save',      SubmitType::class, array('label' => 'Rechercher'))
                ->getForm();

        if($request->isMethod('POST')){ //form filled and submitted

            $form->handleRequest($request);    
            if($form->isValid()){
                $dataForm = $form->getData();            
                $orderBy = $dataForm['orderBy'];
                $before = $dataForm['before'];
                $after = $dataForm['after'];
                $code = $dataForm['code'];

                $cb = $cardRepo->createQueryBuilder('c');
                $cb->where('c.user is NULL')
                    ->orderBy('c.creationDate',$orderBy);

                if($before){
                    $cb->andWhere('c.creationDate <= :beforeDate')
                       ->setParameter('beforeDate',$before);

                }
                if($after){
                    $cb->andWhere('c.creationDate >= :afterDate')
                       ->setParameter('afterDate',$after);
                }

                if($code){
                    $cb->andWhere('c.code = :code')
                       ->setParameter('code',$this->get('cairn_user.security')->vigenereDecode($code));
                }

                $availableCards = $cb->getQuery()->getResult();
            }
        }

        return $this->render('CairnUserBundle:Card:list_available_cards.html.twig',array('form'=>$form->createView(),
                                                                                         'availableCards'=>$availableCards));
    }

    /**
     * Destructs an available card
     *
     *@Security("is_granted('ROLE_ADMIN')")
     *@Method("GET")
     */
    public function destructCardAction(Request $request, Card $card)
    {
        $em = $this->getDoctrine()->getManager();
        $session = $request->getSession();
        //associated card
        if($card->getUser()){
            throw new AccessDeniedException('Cette carte est associée à un compte. Destruction impossible');
        }

        $form = $this->createForm(ConfirmationType::class);
        $form->add('current_password', PasswordType::class, array('label'=> 'Mot de passe','constraints'=> new UserPassword() ));

        if($request->isMethod('POST')){
            $form->handleRequest($request);

            if($form->isValid()){
                if($form->get('save')->isClicked()){
                    $em->remove($card);
                    $em->flush();
                    $session->getFlashBag()->add('success', 'La carte de sécurité a été détruite avec succès !');
                }else{
                    $session->getFlashBag()->add('info', 'La carte de sécurité n\'a pas été détruite.');
                }

                return $this->redirectToRoute('cairn_user_card_list_available');
            }
        }

        return $this->render('CairnUserBundle:Card:destruct_card.html.twig',array('form'=>$form->createView(),'card'=>$card));
    }


    /**
     * Requests for a card revocation
     *
     * To request for a card revocation, the current card of $user must exist. This request can be done by the user himself, or 
     * by one of his referents. To ensure security, the user doing the request is asked to input his password. In case of failure,
     * user's attribute 'passwordTries' is incremented. 3 failures leads to disable the user.
     *
     *@param User $user User whose card will be revoked
     *@throws AccessDeniedException currentUser is not card's owner or referent of card's owner
     *@Method("GET")
     */
    public function revokeCardAction(Request $request, User $user, $_format)
    {
        $em = $this->getDoctrine()->getManager();
        $session = $request->getSession();

        $currentUser = $this->getUser();

        if(! (($user === $currentUser) || ($user->hasReferent($currentUser))) ){
            throw new AccessDeniedException('Vous n\'êtes pas référent de '. $user->getUsername() .'. Vous ne pouvez donc pas poursuivre.');
        }

        $card = $user->getCard();
        if(!$card){
            $session->getFlashBag()->add('info','La carte de sécurité Cairn a déjà été révoquée.Vous pouvez aller en chercher une nouvelle.');
            return $this->redirectToRoute('cairn_user_profile_view',array('_format'=>$_format, 'id'=>$user->getID()));
        }

        $form = $this->createForm(ConfirmationType::class);
        $form->add('current_password', PasswordType::class, array('label'=> 'Mot de passe',
            'constraints'=> new UserPassword() ));

        if($request->isMethod('POST')){
            $form->handleRequest($request);


            if($form->isValid()){
                if($form->get('save')->isClicked()){
                    $saveCode = $card->getCode();
                    $em->remove($card);

                    if($smsData = $user->getSmsData()){
                        $smsData->setSmsEnabled(false);
                        $session->getFlashBag()->add('info','Les fonctionnalités SMS sont désormais bloquées pour le numéro :'.$smsData->getPhoneNumber());
                    }

                    $em->flush();

                    if($currentUser !== $user){
                        $subject = 'Révocation de votre carte de sécurité Cairn';
                        $from = $this->getParameter('cairn_email_noreply');
                        $to = $user->getEmail();
                        $body = $this->renderView('CairnUserBundle:Emails:revoke_card.html.twig');

                        $this->get('cairn_user.message_notificator')->notifyByEmail($subject,$from,$to,$body);
                        $session->getFlashBag()->add('success','La carte de sécurité Cairn de code'.$saveCode.' appartenant à '.$user->getName().' a été révoquée avec succès ! Un email lui a été envoyé pour l\'en informer.');
                    }else{
                        $session->getFlashBag()->add('success','Votre carte de sécurité Cairn a été révoquée avec succès !');
                    }

                }
                else{
                    $session->getFlashBag()->add('info','Vous avez annulé la révocation de la carte de sécurité');
                }

                return $this->redirectToRoute('cairn_user_profile_view',array('_format'=>$_format,'id'=>$user->getID()));

            }
        }

        if($_format == 'json'){
            return $this->json(array('form'=>$form->createView(),'card'=>$card));
        }
        return $this->render('CairnUserBundle:Card:confirm_revoke_card.html.twig',array('form'=>$form->createView(),'card'=>$card));
    }

    /**
     * Generates a zip file containing a list of cards
     *
     * As an admin, an user can generate a list of cards to be associated afterwards. The number of printable cards is limited according
     * to a global variable max_printed_cards. 
     *
     *@Security("is_granted('ROLE_ADMIN')")
     */
    public function generateSetOfCardsAction(Request $request)
    {
        $session = $request->getSession();
        $currentUser = $this->getUser();
        $em = $this->getDoctrine()->getManager();
        $cardRepo = $em->getRepository('CairnUserBundle:Card');

        $nbAvailableCards = count($cardRepo->findAvailableCards());
        $nbPrintableCards = $this->getParameter('max_printable_cards') - $nbAvailableCards;

        if($nbPrintableCards == 0){
            $session->getFlashBag()->add('info','La limite de cartes générables a été atteinte : '.$this->getParameter('max_printable_cards'));
            return $this->redirectToRoute('cairn_user_welcome',array('id'=>$currentUser->getID()));
        }

        $form = $this->createFormBuilder()
            ->add('number', IntegerType::class, array('label' => 'Nombre de cartes'))
            ->add('confirm', SubmitType::class, array('label' => 'Valider'))
            ->getForm();

        if($request->isMethod('POST')){
            $form->handleRequest($request);
            if($form->isValid()){
                $nbRequestedCards =  $form->get('number')->getData();

                if($nbPrintableCards < $nbRequestedCards){
                    $nbRequestedCards = $nbPrintableCards;
                    $session->getFlashBag()->add('info','Vous avez demandé la génération de '.$nbRequestedCards.' mais seules '.$nbPrintableCards.' peuvent être fournies.');
                }

                $uploadDir = $this->getParameter('kernel.project_dir').'/web/uploads/cards/';

                $zip = new \ZipArchive();
                $zipName = 'cards-set-'.time().'.zip';
                $zipWebPath = $uploadDir.$zipName;
//                $zipName = $this->getParameter('kernel.project_dir').'/test.zip';
                $zip->open($zipWebPath,  \ZipArchive::CREATE);

                $files = array();
                for($i=0; $i<$nbRequestedCards; $i++){
                    $snappy = new Pdf($this->getParameter('kernel.project_dir').'/vendor/h4cc/wkhtmltopdf-amd64/bin/wkhtmltopdf-amd64');
//                    $snappy->setTemporaryFolder($this->getParameter('kernel.project_dir').'/temp');

                    $salt = $this->get('cairn_user.security')->generateCardSalt();

                    $uniqueCode = $this->get('cairn_user.security')->findAvailableCode();

                    $card = new Card(NULL,$this->getParameter('cairn_card_rows'),$this->getParameter('cairn_card_cols'),$salt,$uniqueCode);
                    $card->generateCard($this->getParameter('kernel.environment'));
                    $fields = $card->getFields();

                    $html =  $this->renderView('CairnUserBundle:Pdf:card.html.twig',
                        array('card'=>$card,'code'=>$this->get('cairn_user.security')->vigenereEncode($uniqueCode)));

                    $pdfName = 'card-'.time().'-'.$card->getCode().'.pdf';
                    $webPath = $uploadDir.$pdfName;
                    $snappy->generateFromHtml($html,$webPath);
                    $zip->addFromString($pdfName, file_get_contents($webPath));

                    $em->persist($card);

                }

                $zip->close();

                $response = new Response(file_get_contents($zipWebPath));
                $response->headers->set('Content-Type', 'application/zip');
                $response->headers->set('Content-Disposition', 'attachment;filename="' . $zipName . '"');
                $response->headers->set('Content-length', filesize($zipWebPath));

                $em->flush();

                $session->getFlashBag()->add('success',$nbPrintableCards.' ont été téléchargées avec succès !');
                return $response;
            }
        }
        return $this->render('CairnUserBundle:Card:generate_set_cards.html.twig',array('form'=>$form->createView(),
                                                                                       'nbPrintableCards'=>$nbPrintableCards));

    }


    /**
     * Associate new user's card
     *
     * To ensure security, the user is asked to input a code provided on his card. In case of failure, user's attribute 
     * 'cardAssociationTries' is incremented.
     * 3 failures leads to disable the user.
     */
    public function associateCardAction(Request $request, User $user, $_format)
    {
        $session = $request->getSession();
        $currentUser = $this->getUser();
        $em = $this->getDoctrine()->getManager();
        $cardRepo = $em->getRepository('CairnUserBundle:Card');

        if(! (($user === $currentUser) || ($user->hasReferent($currentUser))) ){
            throw new AccessDeniedException('Vous n\'êtes pas référent de '. $user->getUsername() .'. Vous ne pouvez donc pas poursuivre.');
        }

        $card = $user->getCard();
        if($card){
            $session->getFlashBag()->add('info','Une carte a déjà été associée.');
            return $this->redirectToRoute('cairn_user_profile_view',array('_format'=>$_format, 'id'=>$user->getID()));
        }

        if($user->getRemovalRequest() || !$user->isEnabled() ){
            $session->getFlashBag()->add('info',$user->getName().' est soit bloqué, soit en instance de suppression. L\'association de carte est donc impossible');
            return $this->redirectToRoute('cairn_user_profile_view',array('_format'=>$_format, 'id'=>$user->getID()));
        }

        $form = $this->createForm(CardType::class);

        if($request->isMethod('POST')){
            $form->handleRequest($request);
            if($form->isValid()){
                $cardCode =  $form->get('field')->getData();
                $newCard = $cardRepo->findAvailableCardWithCode($this->get('cairn_user.security')->vigenereDecode($cardCode));

                if(!$newCard){
                    $currentUser->setCardAssociationTries($user->getCardAssociationTries() + 1);
                    $remainingTries = 3 - $user->getCardAssociationTries();

                    $session->getFlashBag()->add('error','Ce code ne correspond à aucune carte disponible. Il vous reste '.$remainingTries. ' essais. La carte de sécurité expire au bout de '.$this->getParameter('card_association_delay').' à partir de sa date d\'impression. Peut-être a-t-elle expirée ?');
                    $em->flush();

                    if($this->get('cairn_user.api')->isApiCall()){
                        $response =  new Response('card association : FAILED !');
                        $response->setStatusCode(Response::HTTP_NOT_FOUND);
                        $response->headers->set('Content-Type', 'application/json');
                        return $response;
                    }
                    return new RedirectResponse($request->getRequestUri());
                }else{
                    $currentUser->setCardAssociationTries(0);
                    $user->setCard($newCard);
                    $newCard->setUser($user);
                    $this->get('cairn_user.security')->encodeCard($newCard);
                    $em->flush();

                    if($this->get('cairn_user.api')->isApiCall()){
                        $response =  new Response('card association : OK !');
                        $response->setStatusCode(Response::HTTP_OK);
                        $response->headers->set('Content-Type', 'application/json');
                        return $response;
                    }

                    $session->getFlashBag()->add('success','La carte a été associée avec succès.');
                    return $this->redirectToRoute('cairn_user_profile_view',array('id'=>$user->getID()));
                }

            }
        }

        return $this->render('CairnUserBundle:Card:associate_card.html.twig',array('form'=>$form->createView(),'user'=>$user));

    }


    /**
     * Generates a new card entity, associates it to an user, prints it in PDF format then encodes it in database
     *
     * This action is considered as a sensible operation.
     * Card generation can be done by an admin for user under its responsibility. An exception case is installed SUPER_ADMIN who can 
     * generate a card for himself. The association between user and card is done automatically.
     *
     * The card is encoded in database using user's salt attribute to add a security layer in database.
     *
     *@Security("is_granted('ROLE_ADMIN')")
     *@Method("GET")
     */
    public function downloadAndAssociateCardAction(Request $request, User $user, $_format)
    {
        $session = $request->getSession();
        $em = $this->getDoctrine()->getManager();

        $currentUser = $this->getUser();

        if(! $user->hasReferent($currentUser)){
            throw new AccessDeniedException('Vous n\'êtes pas référent de '. $user->getUsername() .'. Vous ne pouvez donc pas poursuivre.');
        }

        $card = $user->getCard();

        if($card){
            $session->getFlashBag()->add('info',$user->getName().' a déjà une carte de sécurité associée.');
            return $this->redirectToRoute('cairn_user_profile_view',array('_format'=>$_format,'id'=>$user->getID()));
        }

        $form = $this->createForm(ConfirmationType::class);

        if ($request->isMethod('POST') && $form->handleRequest($request)->isValid()) {
            if($form->get('cancel')->isClicked()){
                return $this->redirectToRoute('cairn_user_profile_view', array('_format'=>$_format,'id'=>$user->getID()));
            }

            $salt = $this->get('cairn_user.security')->generateCardSalt();
            $uniqueCode = $this->get('cairn_user.security')->findAvailableCode();

            $card = new Card($user,$this->getParameter('cairn_card_rows'),$this->getParameter('cairn_card_cols'),$salt,$uniqueCode);

            $card->generateCard($this->getParameter('kernel.environment'));

            $user->setCard($card);

            $html =  $this->renderView('CairnUserBundle:Pdf:card.html.twig',
                array('card'=>$card,'code'=>$this->get('cairn_user.security')->vigenereEncode($uniqueCode)));
    
    
            $this->get('cairn_user.security')->encodeCard($card);
            $em->flush();
            $filename = sprintf('carte-sécurité-cairn-'.$card->getID().'-%s.pdf',$user->getUserName());
    
            $session->getFlashBag()->add('success','La carte a été associée à '.$user->getName().' !');
            $session->getFlashBag()->add('info','Pensez à supprimer le fichier de votre ordinateur dès que la carte a été imprimée !');
   
            return new Response(
                $this->get('knp_snappy.pdf')->getOutputFromHtml($html),
                200,
                [
                    'Content-Type'        => 'application/pdf',
                    'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
                ]
            );

        }

        return $this->render('CairnUserBundle:Card:generate.html.twig', array(
            'user' => $user,
            'form'   => $form->createView()
        ));

    }


}
