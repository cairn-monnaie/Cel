<?php
// src/Cairn/UserBundle/Controller/AdminController.php

namespace Cairn\UserBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

//manage Entities
use Cairn\UserBundle\Entity\User;
use Cairn\UserBundle\Entity\Deposit;
use Cairn\UserBundle\Entity\Operation;

use Cairn\UserBundle\Repository\UserRepository;
use Cairn\UserCyclosBundle\Entity\UserManager;
use Cairn\UserCyclosBundle\Entity\BankingManager;

//manage HTTP format
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

//manage Forms
use Cairn\UserBundle\Form\ConfirmationType;
use Cairn\UserCyclosBundle\Form\UserType;
use Symfony\Component\Form\AbstractType;                                       
use Symfony\Component\Form\FormBuilderInterface;                               
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;                   
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;

use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Validator\Constraints as Assert;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Cyclos;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;


/**
 * This class contains actions related to user, cards or accounts management by administrators
 *
 * Adminisatrators can have either a role ROLE_ADMIN (resp. ROLE_SUPER_ADMIN) depending on the level of restrictions and rights
 * @Security("has_role('ROLE_ADMIN')")
 */
class AdminController extends Controller
{
    /**
     * Deals with all user management actions to operate on Cyclos-side
     *@var UserManager $userManager
     */
    private $userManager;                                              


    public function __construct()
    {                                
        $this->userManager = new UserManager();
        $this->bankingManager = new BankingManager();

    }   


    /**
     * Administrator's dashboard to see users status on a single page
     *
     * Sets into groups users by several criteria :
     * _ role (pro, person, admin, superadmin)
     * _ status ( opposed, enabled, waiting for validation)
     * _ waiting for security card
     */
    public function userDashboardAction(Request $request)
    {
        $currentUser = $this->getUser();
        $currentUserID = $currentUser->getID();
        $em = $this->getDoctrine()->getManager();
        $userRepo = $em->getRepository('CairnUserBundle:User');

        $pros = new \stdClass();
        $pros->enabled = $userRepo->findUsersWithStatus($currentUserID,'ROLE_PRO',true);
        $pros->blocked = $userRepo->findUsersWithStatus($currentUserID,'ROLE_PRO',false);
        $pros->pending = $userRepo->findPendingUsers($currentUserID,'ROLE_PRO');
        $pros->nocard = $userRepo->findUsersWithPendingCard($currentUserID,'ROLE_PRO');

        $ub = $userRepo->createQueryBuilder('u');
        $userRepo->whereReferent($ub, $currentUserID)->whereToRemove($ub, true)->whereRole($ub, 'ROLE_PRO');
        $pros->toRemove = $ub->getQuery()->getResult();

        $persons = new \stdClass();
        $persons->enabled = $userRepo->findUsersWithStatus($currentUserID,'ROLE_PERSON',true);
        $persons->blocked = $userRepo->findUsersWithStatus($currentUserID,'ROLE_PERSON',false);
        $persons->pending = $userRepo->findPendingUsers($currentUserID,'ROLE_PERSON');
        $persons->nocard = $userRepo->findUsersWithPendingCard($currentUserID,'ROLE_PERSON');

        $ub = $userRepo->createQueryBuilder('u');
        $userRepo->whereReferent($ub, $currentUserID)->whereToRemove($ub, true)->whereRole($ub, 'ROLE_PERSON');
        $persons->toRemove = $ub->getQuery()->getResult();

        $admins = new \stdClass();
        $admins->enabled = $userRepo->findUsersWithStatus($currentUserID,'ROLE_ADMIN',true);
        $admins->blocked = $userRepo->findUsersWithStatus($currentUserID,'ROLE_ADMIN',false);
        $admins->pending = $userRepo->findPendingUsers($currentUserID,'ROLE_ADMIN');

        $superAdmins = array();

        if($currentUser->hasRole('ROLE_SUPER_ADMIN')){
            $superAdmins = new \stdClass();
            $superAdmins->blocked = $userRepo->findUsersWithStatus($currentUserID,'ROLE_SUPER_ADMIN',false);
            $superAdmins->pending = $userRepo->findPendingUsers($currentUserID,'ROLE_SUPER_ADMIN');
        }

        $allUsers = array(
            'pros'=>$pros, 
            'persons'=>$persons,
            'admins'=>$admins,
            'superAdmins'=>$superAdmins,
        );

        return $this->render('CairnUserBundle:Admin:dashboard.html.twig',array('allUsers'=>$allUsers));
    }

    /**
     * Administrator's dashboard to see data related to electronic money safe on a single page
     *
     * This action retrieves all waiting deposits, their cumulated amount of money and the currently available electronic money
     * @Security("has_role('ROLE_SUPER_ADMIN')")
     */
    public function moneySafeDashboardAction(Request $request)
    {
        $currentUser = $this->getUser();
        $em = $this->getDoctrine()->getManager();
        $userRepo = $em->getRepository('CairnUserBundle:User');
        $depositRepo = $em->getRepository('CairnUserBundle:Deposit');

        //get all deposits with state scheduled + amount of these deposits ordered by date
        $db = $depositRepo->createQueryBuilder('d');
        $depositRepo->whereStatus($db,Deposit::STATE_SCHEDULED);
        $db->orderBy('d.requestedAt','ASC');

        $deposits = $db->getQuery()->getResult();
        $amountOfDeposits = $db->select('sum(d.amount)')->getQuery()->getSingleScalarResult();


        //get amount of available e-mlc
        $accounts = $this->get('cairn_user_cyclos_account_info')->getAccountsSummary($currentUser->getCyclosID(),NULL);

        foreach($accounts as $account){
            if(preg_match('#compte_de_debit_cairn_numerique#', $account->type->internalName)){
                $debitAccount = $account;
            }
        }
        $availableAmount = $debitAccount->status->balance;

        return $this->render('CairnUserBundle:Admin:money_safe_dashboard.html.twig',array('availableAmount'=>$availableAmount, 'deposits'=>$deposits, 'amountOfDeposits'=>$amountOfDeposits));

    }

    /**
     * Declares a new money safe balance 
     *
     * This action allows to declare a new amount of available electronic money, and executes as many waiting deposits as possible
     * according to this new balance, ordered by date of request. The status of these deposits change to "PROCESSED", and equivalent 
     * operations are persisted
     * @Security("has_role('ROLE_SUPER_ADMIN')")
     */
    public function moneySafeEditAction(Request $request)
    {
        $session = $request->getSession();

        $currentUser = $this->getUser();
        $em = $this->getDoctrine()->getManager();
        $userRepo = $em->getRepository('CairnUserBundle:User');
        $depositRepo = $em->getRepository('CairnUserBundle:Deposit');

        //get amount of available e-mlc
        $accounts = $this->get('cairn_user_cyclos_account_info')->getAccountsSummary($currentUser->getCyclosID(),NULL);

        foreach($accounts as $account){
            if(preg_match('#compte_de_debit_cairn_numerique#', $account->type->internalName)){
                $debitAccount = $account;
            }
        }
        $availableAmount = $debitAccount->status->balance;

        $form = $this->createFormBuilder()
            ->add('amount',    NumberType::class, array('label' => 'Nombre de [e]-cairns actuellement gagés'))
            ->add('save',      SubmitType::class, array('label' => 'Confirmation'))
            ->getForm();

        if($request->isMethod('POST')){
            $form->handleRequest($request);    
            if($form->isValid()){
                $dataForm = $form->getData();            
                $newAvailableAmount = $dataForm['amount'];

                $bankingService = $this->get('cairn_user_cyclos_banking_info');

                $paymentData = $bankingService->getPaymentData('SYSTEM','SYSTEM',NULL);
                foreach($paymentData->paymentTypes as $paymentType){
                    if(preg_match('#creation_mlc_numeriques#', $paymentType->internalName)){
                        $creditTransferType = $paymentType;
                    }
                }
                $amountToCredit = $newAvailableAmount - $availableAmount;
                $description = 'Declaration de '.$newAvailableAmount .' [e]-cairns disponibles par '.$currentUser->getName().' le '.date('d-m-Y');


                $res = $this->bankingManager->makeSinglePreview($paymentData,$amountToCredit,$description,$creditTransferType,new \Datetime());
                $paymentVO = $this->bankingManager->makePayment($res->payment);

                //get all deposits with state scheduled + amount of these deposits ordered by date
                $db = $depositRepo->createQueryBuilder('d');
                $depositRepo->whereStatus($db,Deposit::STATE_SCHEDULED);
                $db->orderBy('d.requestedAt','ASC');

                $deposits = $db->getQuery()->getResult();

                $reason = 'Acompte post virement Helloasso'; 

                //while there is enough available electronic mlc, credit user
                foreach($deposits as $deposit){
                    if($deposit->getAmount() <= $newAvailableAmount){
                        var_dump($deposit->getID());
                        $paymentData = $bankingService->getPaymentData('SYSTEM',$deposit->getCreditor()->getCyclosID(),NULL);
                        foreach($paymentData->paymentTypes as $paymentType){
                            if(preg_match('#credit_du_compte#', $paymentType->internalName)){
                                $creditTransferType = $paymentType;
                            }
                        }

                        $now = new \Datetime();
                        $res = $this->bankingManager->makeSinglePreview($paymentData,$deposit->getAmount(),$reason,$creditTransferType,$now);
                        $paymentVO = $this->bankingManager->makePayment($res->payment);

                        $deposit->setStatus(Deposit::STATE_PROCESSED);
                        $deposit->setExecutedAt($now);

                        $operation = new Operation();
                        $operation->setType(Operation::TYPE_CONVERSION_HELLOASSO);
                        $operation->setReason($reason);
                        $operation->setPaymentID($paymentVO->id);
                        $operation->setFromAccountNumber($res->fromAccount->number);
                        $operation->setToAccountNumber($res->toAccount->number);
                        $operation->setAmount($res->totalAmount->amount);
                        $operation->setDebitorName($this->get('cairn_user_cyclos_user_info')->getOwnerName($res->fromAccount->owner));
                        $operation->setCreditor($deposit->getCreditor());

                        $em->persist($operation);

                        $newAvailableAmount -= $deposit->getAmount();
                    }
                }


                $session->getFlashBag()->add('info','Des crédits de compte [e]-cairns ont peut-être été exécutés');
                $em->flush();

                return $this->redirectToRoute('cairn_user_electronic_mlc_dashboard');


            }
        }

        return $this->render('CairnUserBundle:Admin:money_safe_edit.html.twig',array('form' => $form->createView(),'availableAmount'=>$availableAmount));

    }

    /**
     * Set the enabled attribute of user with provided ID to true
     *
     * An email is sent to the user being (re)activated
     *
     * @throws  AccessDeniedException Current user trying to activate access is not a referent of the user being involved
     * @Method("GET")
     */ 
    public function activateUserAction(Request $request, User $user, $_format)
    {
        $session = $request->getSession();
        $em = $this->getDoctrine()->getManager();
        $userRepo = $em->getRepository('CairnUserBundle:User');

        $currentUser = $this->getUser();

        if(! $user->hasReferent($currentUser)){
            throw new AccessDeniedException('Vous n\'êtes pas référent de '. $user->getUsername() .'. Vous ne pouvez donc pas poursuivre.');
        }elseif($user->isEnabled()){
            $session->getFlashBag()->add('info','L\'espace membre de ' . $user->getName() . ' est déjà accessible.');
            return $this->redirectToRoute('cairn_user_profile_view',array('username' => $user->getUsername()));
        }elseif($user->getConfirmationToken()){
            throw new AccessDeniedException('Email non confirmé, cet utilisateur ne peut être validé');
        }

        $form = $this->createForm(ConfirmationType::class);

        if ($request->isMethod('POST') && $form->handleRequest($request)->isValid()) {
            if($form->get('save')->isClicked()){

                $messageNotificator = $this->get('cairn_user.message_notificator');

                //if first activation : create user in cyclos and ask if generate card now
                if(! $user->getLastLogin()){
                    try{
                        $userVO = $this->get('cairn_user_cyclos_user_info')->getUserVO($user->getCyclosID());
                        $this->get('cairn_user.access_platform')->enable(array($user));
                    }catch(\Exception $e){
                        if(! $e->errorCode == 'ENTITY_NOT_FOUND'){
                            throw $e;
                        }else{
                            //create cyclos user
                            $userDTO = new \stdClass();                                    
                            $userDTO->name = $user->getName();                             
                            $userDTO->username = $user->getUsername();                     
                            $userDTO->login = $user->getUsername();                        
                            $userDTO->email = $user->getEmail();                           

                            $temporaryPassword = User::randomPassword();
                            $user->setPlainPassword($temporaryPassword);

                            $password = new \stdClass();                                   
                            $password->assign = true;                                      
                            $password->type = 'login';
                            $password->value = $temporaryPassword;
                            $password->confirmationValue = $password->value;
                            $userDTO->passwords = $password;                               

                            if($user->hasRole('ROLE_PRO')){
                                $groupName = $this->getParameter('cyclos_group_pros');  
                            }elseif($user->hasRole('ROLE_PERSON')){
                                $groupName = $this->getParameter('cyclos_group_persons');  
                            }else{                                                                 
                                $groupName = $this->getParameter('cyclos_group_network_admins');
                            }

                            $groupVO = $this->get('cairn_user_cyclos_group_info')->getGroupVO($groupName);

                            //if webServices channel is not added, it is impossible to update/remove the cyclos user entity from 3rd party app
                            $webServicesChannelVO = $this->get('cairn_user_cyclos_channel_info')->getChannelVO('webServices');

                            $newUserCyclosID = $this->userManager->addUser($userDTO,$groupVO,$webServicesChannelVO);
                            $user->setCyclosID($newUserCyclosID);

                            if($user->isAdherent()){
                                $icc_account = $this->get('cairn_user_cyclos_account_info')->getDefaultAccount($newUserCyclosID);
                                $icc = $icc_account->number;
                                $user->setMainICC($icc);
                            }


                            //activate user and send email to user
                            $body = $this->renderView('CairnUserBundle:Emails:welcome.html.twig',
                                array('user'=>$user,
                                'login_url'=>$this->get('router')->generate('fos_user_security_login')));
                            $subject = 'Plateforme numérique du Cairn';

                            $this->get('cairn_user.access_platform')->enable(array($user), $subject, $body);

                            //send email to local group referent if pro

                            if($user->hasRole('ROLE_PRO') && ($referent = $user->getLocalGroupReferent()) ){
                                $from = $messageNotificator->getNoReplyEmail();
                                $to = $referent->getEmail();
                                $subject = 'Référent Pro';
                                $body = 'Vous êtes désormais GL référent du professionnel ' . $user->getName();
                                $messageNotificator->notifyByEmail($subject,$from,$to,$body);
                            }

                            $session->getFlashBag()->add('success','L\'utilisateur ' . $user->getName() . ' a été activé. Il peut accéder à la plateforme.');
                            $em->flush();
                            return $this->redirectToRoute('cairn_user_card_associate',array('id'=>$user->getID()));
                        }
                    }
                }else{
                    $this->get('cairn_user.access_platform')->enable(array($user));
                }
            }

            $em->flush();
            $session->getFlashBag()->add('success','L\'utilisateur ' . $user->getName() . ' a été activé. Il peut accéder à la plateforme.');
            return $this->redirectToRoute('cairn_user_profile_view',array('_format'=>$_format, 'username' => $user->getUsername()));
        }

        $responseArray = array('user' => $user,'form'=> $form->createView());

        return $this->render('CairnUserBundle:User:activate.html.twig', $responseArray);

    }

    /**
     * Assign a unique local group (ROLE_ADMIN) as a referent of @param
     *
     * @param  User $user  User entity the referent is assigned to
     * @Security("has_role('ROLE_SUPER_ADMIN')")
     */
    public function assignReferentAction(Request $request, User $user)
    {
        if(!$user->hasRole('ROLE_PRO')){
            throw new AccessDeniedException('Seuls les professionnels doivent avoir des référents assignés manuellement.');
        }

        $session = $request->getSession();
        $em = $this->getDoctrine()->getManager();
        $userRepo = $em->getRepository('CairnUserBundle:User');

        $choices = $userRepo->myFindByRole(array('ROLE_ADMIN'));

        $messageNotificator = $this->get('cairn_user.message_notificator');
        $from = $messageNotificator->getNoReplyEmail();

        $form = $this->createFormBuilder()
            ->add('singleReferent', EntityType::class, array(
                'class'=>User::class,
                'constraints'=>array(                              
                    new Assert\NotNull()                           
                ),
                'choice_label'=>'name',
                'choice_value'=>'username',
                'data'=> $user->getLocalGroupReferent(),
                'choices'=>$choices,
                'expanded'=>true,
                'required'=>false
            ))
            ->add('cancel', SubmitType::class, array('label'=>'Annuler'))
            ->add('save', SubmitType::class, array('label'=>'Assigner'))
            ->getForm();

        if($request->isMethod('POST')){
            $form->handleRequest($request);
            if($form->get('save')->isClicked()){
                $referent = $form->get('singleReferent')->getData();
                $currentAdminReferent = $user->getLocalGroupReferent();

                if($referent && !$referent->hasRole('ROLE_ADMIN')){
                    throw new AccessDeniedException('Seul un groupe local peut être assigné via ce formulaire.');
                }
                if($referent){
                    if($user->hasReferent($referent)){
                        $session->getFlashBag()->add('info',
                            $referent->getName() . ' est déjà le groupe local référent de '.$user->getName());
                        return new RedirectResponse($request->getRequestUri());
                    }
                }

                if(!$currentAdminReferent && !$referent){
                    $session->getFlashBag()->add('info',$user->getName(). ' n\'avait pas de groupe local référent.');
                    return new RedirectResponse($request->getRequestUri());
                }

                if($currentAdminReferent){
                    $to = $currentAdminReferent->getEmail();
                    $subject = 'Référent Professionnel e-Cairn';
                    $body = 'Votre GL n\'est plus référent du professionnel ' . $user->getName();
                    $messageNotificator->notifyByEmail($subject,$from,$to,$body);
                    $user->removeReferent($currentAdminReferent);
                }
                if($referent){
                    $user->addReferent($referent);

                    $to = $referent->getEmail();
                    $subject = 'Référent Professionnel e-Cairn';
                    $body = 'Vous êtes désormais GL référent du professionnel ' . $user->getName();
                    $messageNotificator->notifyByEmail($subject,$from,$to,$body);

                    $session->getFlashBag()->add('success',
                        $referent->getName() . ' est désormais référent de '.$user->getName());
                }else{
                    $session->getFlashBag()->add('success',
                        $user->getName(). ' n\'a plus de groupe local référent.');
                }

                $em->flush();
                return $this->redirectToRoute('cairn_user_profile_view',array('username'=>$user->getUsername()));
            }else{
                return $this->redirectToRoute('cairn_user_profile_view',array('username'=>$user->getUsername()));
            }
        }
        return $this->render('CairnUserBundle:User:add_referent.html.twig',array('form'=>$form->createView(),'user'=>$user));
    }   


}
