<?php

namespace App\Controller;

use Dompdf\Dompdf;
use Dompdf\Options;
use App\Entity\Ticket;
use App\Form\TicketType;
use Psr\Log\LoggerInterface;
use App\Controller\MailerController;
use App\Repository\TicketRepository;
use Symfony\Component\Workflow\Registry;
use Doctrine\Persistence\ManagerRegistry;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * @Route("{_locale}/ticket", requirements={"_locale": "en|fr"})
 */
class TicketController extends AbstractController
{
    protected MailerInterface $mailer;
    protected TicketRepository $ticketRepository;
    protected TranslatorInterface $translator;
    protected LoggerInterface $logger;

    public function __construct(TicketRepository $ticketRepository, TranslatorInterface $translator, MailerInterface $mailer, Registry $registry, LoggerInterface $logger){
        $this->ticketRepository = $ticketRepository;
        $this->ts = $translator;
        $this->mailer = $mailer;
        $this->registry = $registry;
        $this->logger = $logger;
    }

    /**
     * @Route("/", name="app_ticket")
     */
    public function index(): Response
    {
        if($this->getUser()){
            $userMail = $this->getUser()->getUserIdentifier();
            $userPwd = $this->getUser()->getPassword();
            $userRole = $this->getUser()->getRoles();

            $this->logger->info('EMAIL', array($userMail));
            $this->logger->info('PASSWORD', array($userPwd));
            $this->logger->info('ROLE', array($userRole));
        }
        
        $user = $this->getUser();
        $tickets = $this->ticketRepository->findBy(['user' => $user] );

        //dd($tickets);

        return $this->render('ticket/index.html.twig', [
            'tickets' => $tickets,
        ]);

        
    }

    /**
     * @Route("/create", name="ticket_create")
     * @Route("/update/{id}", name="ticket_update", requirements={"id"="\d+"})
     */
    public function ticket(Ticket $ticket = null, Request $request) : Response
    {
        $user = $this->getUser();

        if(!$ticket){
        $ticket = new Ticket;

        $ticket->setTicketStatut('initial')
            ->setUser($user)
            ->setCreatedAt(new \DateTimeImmutable());
        //$title = 'Création d\'un ticket';
        $title = $this->ts->trans("title.ticket.create");
        } else {
            $workflow = $this->registry->get($ticket, 'ticketTraitement');
            if ($ticket->getTicketStatut() != 'wip'){
                $workflow->apply($ticket, 'to_wip');
            }
            $title = "Update du formulaire :  {$ticket->getId()}";
            $title = $this->ts->trans("title.ticket.update")."{$ticket->getId()}";
        }

        $form = $this->createForm(TicketType::class, $ticket, []);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
           
                //Nouveauté Symfony 5.4
                $this->ticketRepository->add($ticket, true);

                if ($request->attributes->get("_route")==="ticket_create") {
                    $this->addFlash('success', 'Le ticket a été créé avec succès');
                    MailerController::sendEmail($this->mailer, "user1@test.fr", "Ticket ajouté", " a bien été ajouté", $ticket);
                } else {
                    $this->addFlash('info', 'Le ticket a bien été mis à jour');
                    MailerController::sendEmail($this->mailer, "user1@test.fr", "Ticket modifié", " a bien été modifié", $ticket);
                }
                
                return $this->redirectToRoute('app_ticket');
        }
        return $this->render('ticket/userForm.html.twig', [
            'form' => $form->createView(),
            'title' =>'Création d\'un ticket'
        ]);
    }

    /**
     * @Route("/delete/{id}", name="ticket_delete", requirements={"id"="\d+"})
     */
    public function deleteTicket(Ticket $ticket, MailerInterface $mailer) : Response
    {
        $this->ticketRepository->remove($ticket, true);
        $this->addFlash('danger', 'Le ticket a bien été supprimé');
        MailerController::sendEmail($this->mailer, "user1@test.fr", "Ticket Supprimé", " a bien été supprimé", $ticket);
        
        return $this->redirectToRoute('app_ticket');

    }

    /** 
 * @Route("/close/{id}", name="ticket_close",requirements={"id"="\d+"})
 */
    public function closeTicket(Ticket $ticket): Response
    {
        $workflow = $this->registry->get($ticket, 'ticketTraitement');
        $workflow->apply($ticket, 'to_finished');
        $this->ticketRepository->add($ticket, true);

        return $this->redirectToRoute('app_ticket');
    }

    /**
     * @Route("/pdf", name="ticket_pdf")
     */
     public function pdf() : Response
     {
        //Données utiles
        $user = $this->getUser();
        $tickets = $this->ticketRepository->findBy(['user' => $user] );

         //Configuration de Dompdf
        $pdfOptions = new Options();
        $pdfOptions->set('defaultFont', 'Arial');

        //Instantiation de Dompdf
        $dompdf = new Dompdf($pdfOptions);

        //Récupération du contenu de la vue
        $html = $this->renderView('ticket/pdf.html.twig', [
            'tickets' => $tickets,
            'title' => "Bienvenue sur notre page PDF"
        ]);

        //Ajout du contenu de la vue dans le PDF
        $dompdf->loadHtml($html);

        //Configuration de la taille et de la largeur du PDF
        $dompdf->setPaper('A4', 'portrait');

        //Render du PDF
        $dompdf->render();

        //dd($html);

        //Création du fichier PDF   
        $dompdf->stream("ticket.pdf", [
            "Attachment" => true
        ]);

      return new Response ('', 200, [
          'Content-Type' => 'application/pdf'
      ]);    
     }

     /**
      * @Route("/excel", name="ticket_excel")
      */
     public function excel() : Response {

            //Données utiles
            $user = $this->getUser();
            $tickets = $this->ticketRepository->findBy(['user' => $user] );


            $spreadsheet = new Spreadsheet ();
            $sheet = $spreadsheet->getActiveSheet ();
            $sheet->setCellValue ('A1', 'Liste des tickets pour l\'utilisateur : ' . $user->getUsername());
            $sheet->mergeCells('A1:E1');
            $sheet->setTitle("Liste des tickets");

            //Set Column names
            $columnNames = [
                'Id',
                'Objet',
                'Date de création',
                'Department',
                'Statut',
            ];
            $columnLetter = 'A';
            foreach ($columnNames as $columnName) {
                $sheet->setCellValue ($columnLetter . '3', $columnName);
                $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
                $columnLetter++;
            }
             foreach ($tickets as $key => $ticket) {
                $sheet->setCellValue ('A' . ($key + 4), $ticket->getId());
                $sheet->setCellValue ('B' . ($key + 4), $ticket->getObject());
                $sheet->setCellValue ('C' . ($key + 4), $ticket->getCreatedAt()->format('d/m/Y'));
                $sheet->setCellValue ('D' . ($key + 4), $ticket->getDepartment()->getName());
                $sheet->setCellValue ('E' . ($key + 4), $ticket->getTicketStatut());
            }

            // -- Style de la feuille de calcul --
            $styleArrayHead = [
                'font' => [
                    'bold' => true,
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                ],
                'borders' => [
                    'outline' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    ],
                    'vertical' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    ],
                ],
            ];
                
            $styleArray = [
                        'font' => [
                            'bold' => true,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                        ],
                        'borders' => [
                            'top' => [
                                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            ],
                        ],
                        'fill' => [
                            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_GRADIENT_LINEAR,
                            'rotation' => 90,
                            'startColor' => [
                                'argb' => 'FFA0A0A0',
                            ],
                            'endColor' => [
                                'argb' => 'FFFFFFFF',
                            ],
                        ],
                    ];

            $styleArrayBody = [
                'alignement' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                ],
                'borders' => [
                    'outline' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    ],
                    'vertical' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    ],
                    'horizontal' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    ],
                ],
            ];

            $sheet->getStyle('A1:E1')->applyFromArray($styleArray);
            $sheet->getStyle('A3:E3')->applyFromArray($styleArrayHead);
            $sheet->getStyle('A4:E' . (count($tickets) + 3))->applyFromArray($styleArrayBody);
            
            //Création du fichier xlsx
            $writer = new Xlsx($spreadsheet);

            //Création d'un fichier temporaire
            $fileName = "Export_Tickets.xlsx";
            $temp_file = tempnam(sys_get_temp_dir(), $fileName);

            //créer le fichier excel dans le dossier tmp du systeme
            $writer->save($temp_file);

            //Renvoie le fichier excel
            return $this->file($temp_file, $fileName, ResponseHeaderBag::DISPOSITION_INLINE);
     }

      /**
     * @Route("/details/{id}", name="ticket_detail", requirements={"id"="\d+"})
     */
    public function detailTicket(Ticket $ticket) : Response
    {
        return $this->render('ticket/detail.html.twig', ['ticket' => $ticket]);
    }

}
