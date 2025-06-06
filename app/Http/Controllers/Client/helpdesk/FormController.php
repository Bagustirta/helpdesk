<?php

namespace App\Http\Controllers\Client\helpdesk;

// controllers
use App\Http\Controllers\Agent\helpdesk\TicketWorkflowController;
use App\Http\Controllers\Common\FileuploadController;
use App\Http\Controllers\Controller;
// requests
use App\Http\Requests\helpdesk\ClientRequest;
// models
use App\Model\helpdesk\Form\Fields;
use App\Model\helpdesk\Manage\Help_topic;
use App\Model\helpdesk\Settings\CommonSettings;
use App\Model\helpdesk\Settings\System;
use App\Model\helpdesk\Settings\Ticket;
use App\Model\helpdesk\Ticket\Ticket_attachments;
use App\Model\helpdesk\Ticket\Ticket_Priority;
use App\Model\helpdesk\Ticket\Ticket_source;
use App\Model\helpdesk\Ticket\Ticket_Thread;
use App\Model\helpdesk\Ticket\Tickets;
use App\Model\helpdesk\Utility\CountryCode;
use App\Model\helpdesk\Agent\Department;
use App\User;
use Exception;
// classes
use Auth;
use Form;
use GeoIP;
use Illuminate\Http\Request;
use Lang;
use Redirect;

/**
 * FormController.
 *
 * @author      Ladybird <info@ladybirdweb.com>
 */
class FormController extends Controller
{
    /**
     * @var FileuploadController
     */
    protected $fileUploadController;

    /**
     * Create a new controller instance.
     * Constructor to check.
     *
     * @return void
     */
    public function __construct(TicketWorkflowController $TicketWorkflowController, FileuploadController $fileUploadController)
    {
        $this->middleware('board');
        // creating a TicketController instance
        $this->TicketWorkflowController = $TicketWorkflowController;
        $this->fileUploadController = $fileUploadController;
    }

    /**
     * getform.
     *
     * @param type Help_topic $topic
     *
     * @return type
     */
    public function getForm(Help_topic $topic, CountryCode $code)
    {
        if (\Config::get('database.install') == '%0%') {
            return \Redirect::route('licence');
        }
        $settings = CommonSettings::select('status')->where('option_name', '=', 'send_otp')->first();
        $email_mandatory = CommonSettings::select('status')->where('option_name', '=', 'email_mandatory')->first();
        if (!\Auth::check() && ($settings->status == 1 || $settings->status == '1')) {
            return redirect('auth/login')->with(['login_require' => 'Please login to your account for submitting a ticket', 'referer' => 'form']);
        }
        $location = GeoIP::getLocation();
        $phonecode = $code->where('iso', '=', $location->iso_code)->first();
        if (System::first()->status == 1) {
            $topics = $topic->get();
            $codes = $code->get();
            if ($phonecode->phonecode) {
                $phonecode = $phonecode->phonecode;
            } else {
                $phonecode = '';
            }

            [$max_size_in_bytes, $max_size_in_actual] = $this->fileUploadController->file_upload_max_size();

            return view('themes.default1.client.helpdesk.form', compact('topics', 'codes', 'email_mandatory', 'max_size_in_bytes', 'max_size_in_actual'))->with('phonecode', $phonecode);
        } else {
            return \Redirect::route('home');
        }
    }

    /**
     * This Function to post the form for the ticket.
     *
     * @param type Form_name    $name
     * @param type Form_details $details
     *
     * @return type string
     */
    public function postForm($id, Help_topic $topic)
    {
        if ($id != 0) {
            $helptopic = $topic->where('id', '=', $id)->first();
            $custom_form = $helptopic->custom_form;
            $values = Fields::where('forms_id', '=', $custom_form)->get();
            if (!$values) {
            }
            if ($values) {
                foreach ($values as $form_data) {
                    if ($form_data->type == 'select') {
                        $form_fields = explode(',', $form_data->value);
                        $var = '';
                        foreach ($form_fields as $form_field) {
                            $var .= '<option value="'.$form_field.'">'.$form_field.'</option>';
                        }
                        echo '<br/><label>'.ucfirst($form_data->label).'</label><select class="form-control" name="'.$form_data->name.'">'.$var.'</select>';
                    } elseif ($form_data->type == 'radio') {
                        $type2 = $form_data->value;
                        $vals = explode(',', $type2);
                        echo '<br/><label>'.ucfirst($form_data->label).'</label><br/>';
                        foreach ($vals as $val) {
                            echo '<input type="'.$form_data->type.'" name="'.$form_data->name.'"> '.$form_data->value.'&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
                        }
                        echo '<br/>';
                    } elseif ($form_data->type == 'textarea') {
                        $type3 = $form_data->value;
                        echo '<br/><label>'.$form_data->label.'</label></br><textarea id="unique-textarea" name="'.$form_data->name.'" class="form-control" style="height:15%;"></textarea>';
                    } elseif ($form_data->type == 'checkbox') {
                        $type4 = $form_data->value;
                        $checks = explode(',', $type4);
                        echo '<br/><label>'.ucfirst($form_data->label).'</label><br/>';
                        foreach ($checks as $check) {
                            echo '<input type="'.$form_data->type.'" name="'.$form_data->name.'">&nbsp&nbsp'.$check;
                        }
                    } else {
                        echo '<br/><label>'.ucfirst($form_data->label).'</label><input type="'.$form_data->type.'" class="form-control"   name="'.$form_data->name.'" />';
                    }
                }
                echo '<br/><br/>';
            }
        } else {
            return;
        }
    }

    /**
     * Posted form.
     *
     * @param type Request $request
     * @param type User    $user
     */
    public function postedForm(User $user, ClientRequest $request, Ticket $ticket_settings, Ticket_source $ticket_source, Ticket_attachments $ta, CountryCode $code)
    {
        try {
            $form_extras = $request->except('Name', 'Phone', 'Email', 'Subject', 'Details', 'helptopic', '_wysihtml5_mode', '_token', 'mobile', 'Code', 'priority', 'attachment');
            $name = $request->input('Name');
            $phone = $request->input('Phone');
            if ($request->input('Email')) {
                if ($request->input('Email')) {
                    $email = $request->input('Email');
                } else {
                    $email = null;
                }
            } else {
                $email = null;
            }
            $subject = $request->input('Subject');
            $details = $request->input('Details');
            $phonecode = $request->input('Code');
            if ($request->input('mobile')) {
                $mobile_number = $request->input('mobile');
            } else {
                $mobile_number = null;
            }
            $status = $ticket_settings->first()->status;
            $helptopic = $request->input('helptopic');
            $helpTopicObj = Help_topic::where('id', '=', $helptopic);
            if ($helpTopicObj->exists() && ($helpTopicObj->value('status') == 1)) {
                $department = $helpTopicObj->value('department');
            } else {
                $defaultHelpTopicID = Ticket::where('id', '=', '1')->first()->help_topic;
                $department = Help_topic::where('id', '=', $defaultHelpTopicID)->value('department');
            }
            $sla = $ticket_settings->first()->sla;

            // $priority = $ticket_settings->first()->priority;
            $default_priority = Ticket_Priority::where('is_default', '=', 1)->first();
            $user_priority = CommonSettings::where('option_name', '=', 'user_priority')->first();
            if (!$request->input('priority')) {
                $priority = $default_priority->priority_id;
                if ($helpTopicObj->exists() && ($helpTopicObj->value('status') == 1)) {
                    $priority = $helpTopicObj->value('priority');
                }
            } else {
                $priority = $request->input('priority');
            }
            $source = $ticket_source->where('name', '=', 'web')->first()->id;
            $attachments = $request->file('attachment');
            $collaborator = null;
            $assignto = null;
            if ($helpTopicObj->exists() && ($helpTopicObj->value('status') == 1)) {
                $assignto = $helpTopicObj->value('auto_assign');
            }
            $auto_response = 0;
            $team_assign = null;
            if ($phone != null || $mobile_number != null) {
                $location = GeoIP::getLocation();
                $geoipcode = $code->where('iso', '=', $location->iso_code)->first();
                if ($phonecode == null) {
                    $data = [
                        'fails'              => Lang::get('lang.country-code-required-error'),
                        'phonecode'          => $geoipcode->phonecode,
                        'country_code_error' => 1,
                    ];

                    return Redirect::back()->with($data)->withInput($request->except('password'));
                } else {
                    $code = CountryCode::select('phonecode')->where('phonecode', '=', $phonecode)->get();
                    if (!count($code)) {
                        $data = [
                            'fails'              => Lang::get('lang.incorrect-country-code-error'),
                            'phonecode'          => $geoipcode->phonecode,
                            'country_code_error' => 1,
                        ];

                        return Redirect::back()->with($data)->withInput($request->except('password'));
                    }
                }
            }
            event(new \App\Events\ClientTicketFormPost($form_extras, $email, $source));
            $result = $this->TicketWorkflowController->workflow($email, $name, $subject, $details, $phone, $phonecode, $mobile_number, $helptopic, $sla, $priority, $source, $collaborator, $department, $assignto, $team_assign, $status, $form_extras, $auto_response);
            // dd($result);
            if ($result[1] == 1) {
                $ticket = Tickets::where('ticket_number', '=', $result[0])->first();
                $thread = Ticket_Thread::where('ticket_id', '=', $ticket->id)->first();
                
                if ($attachments != null) {
                    $storage = new \App\FaveoStorage\Controllers\StorageController();
                    $storage->saveAttachments($thread->id, $attachments);           
                    
                    // // Kirim pesan WhatsApp -> api ke agent yang sesuai
                    // try {
                    //     // Ambil data tiket
                    //     $tickets = Tickets::where('ticket_number', $result[0])->first();
                        
                    //     // Ambil help topic dari tiket
                    //     $helpTopicId = $tickets->help_topic_id;
                    //     $helpTopic = \App\Model\helpdesk\Manage\Help_topic::where('id', $helpTopicId)->first();
                    //     if (!$tickets || !$tickets->help_topic_id) {
                    //         \Log::warning("Tiket tidak ditemukan atau tidak punya help topic");
                    //         return;
                    //     }
                    
                    //     if (!$helpTopic) {
                    //         \Log::error("Help topic tidak ditemukan");
                    //         return;
                    //     }
                    
                    //     // Ambil departemen dari help topic
                    //     $department = Department::find($helpTopic->department);

                    //     if (!$department) {
                    //         \Log::error("Departemen tidak ditemukan untuk help topic dengan ID: " . $helpTopic->id);
                    //         return;  // Menghentikan eksekusi jika departemen tidak ditemukan
                    //     }

                    //     // Ambil nama departemen dengan aman
                    //     $departmentId= $department->id;

                    
                    //     // Cari agen berdasarkan departemen yang sesuai
                    //     $agent = \App\User::where('primary_dpt', $departmentId)->first();
                    
                    //     if (!$agent) {
                    //         \Log::warning("Agen tidak ditemukan untuk departemen ID {$departmentId}");
                    //         return;
                    //     } else {
                    //         $mobile = $agent->mobile;
                    //         $phone = $agent->phone_number;
                    
                    //         \Log::info('Nomor WA agen:', ['mobile' => $mobile, 'phone' => $phone]);
                    
                    //         $nomorWa = $mobile ?: $phone;
                    
                    //         if (!$nomorWa) {
                    //             \Log::warning("Nomor WA agen kosong");
                    //         }
                    //     }
                    
                    //     \Log::info('Nomor WA agen:', ['mobile' => $agent->mobile, 'phone' => $agent->phone_number]);
                    
                    //     if (!$nomorWa) {
                    //         \Log::warning("Nomor WA agen kosong");
                    //         return;
                    //     }
                    
                    //     // Ambil deskripsi atau pesan tiket
                    //     $pesan = $request->input('Details');
                    //     if (!$pesan) {
                    //         \Log::warning("Deskripsi tiket kosong");
                    //         return;
                    //     }
                    
                    //     // Format nomor
                    //     $waNumber = $nomorWa[0] == '0' ? '62' . substr($nomorWa, 1) : '62' . preg_replace('/[^0-9]/', '', $nomorWa);
                    //     if (!preg_match('/^62[1-9][0-9]{7,10}$/', $waNumber)) {
                    //         \Log::warning("Nomor WA agen tidak valid: " . $waNumber);
                    //         return;
                    //     }
                        
                    //     $priority = \App\Model\helpdesk\Ticket\Ticket_Priority::find($ticket->priority_id);
                    //     $ticket_priority = $priority ? $priority->priority_desc : 'Tidak ada';
                    
                    //     // Defining the message
                    //     $message = "📥 Ada tiket baru dari user\n" .
                    //                "Nomor Tiket : " . $tickets->ticket_number . "\n" .
                    //                "Pemohon     : " . $name . "\n" .
                    //                "Subjek      : " . $subject . "\n" .
                    //                "Kategori    : " . $helpTopic->topic . "\n" .
                    //                "Departemen  : " . $department->name . "\n" .
                    //                "Priority    : " . $ticket_priority .  "\n" .
                    //                "\n" .
                    //                "Pesan       : " . strip_tags($pesan) . "\n";
                    
                    //     // Send the WhatsApp message
                    //     $response = \Illuminate\Support\Facades\Http::withBasicAuth(env('WA_API_AUTH_USER'), env('WA_API_AUTH_PASS'))
                    //             ->post(env('WA_API_URL'), [
                    //                 'phone' => $waNumber . '@s.whatsapp.net',
                    //                 'message' => $message,
                    //             ]);
                    
                    //     \Log::debug("Help Topic ID: " . $helpTopicId);
                    //     \Log::info('WA ke agen dikirim: ' . $response->body());
                    
                    // } catch (\Exception $e) {
                    //     \Log::error('Gagal kirim WA ke agen: ' . $e->getMessage());
                    // }
                

                     try {
                            $department = \App\Model\helpdesk\Agent\Department::find($ticket->dept_id);
                            $phone_dept = $department ? $department->phone_num : null;

                            $waNumber = $phone_dept ? ($phone_dept[0] == '0' ? '62' . substr($phone_dept, 1) : '62' . $phone_dept) : null;

                            $priority = \App\Model\helpdesk\Ticket\Ticket_Priority::find($ticket->priority_id);                        
                            $status = \App\Model\helpdesk\Ticket\Ticket_Status::find($ticket->status);
                            $helptopic = \App\Model\helpdesk\Manage\Help_topic::find($ticket->help_topic_id);

                            $waMessage = "📥 Ticket Masuk dengan Nomer ticket : {$ticket_num}\n\n"
                                        . "Pemohon   : {$name}\n"
                                        . "Kategori  : {$helptopic_name}\n"
                                        . "Subject   : {$subject}\n"
                                        . "Priority  : {$ticket_priority}\n"
                                        . "Permohonan :\n" . strip_tags($details);
                        
                            $response = \Illuminate\Support\Facades\Http::withBasicAuth(env('WA_API_AUTH_USER'), env('WA_API_AUTH_PASS'))
                                    ->post(env('WA_API_URL'), [
                                    'phone' => $waNumber . '@s.whatsapp.net',
                                    'message' => $waMessage,
                                ]);
                        
                            $responseData = $response->json();
                       
                        } catch (\Exception $waException) {
                            \Log::error('WA exception (Uji Coba): ' . $waException->getMessage());
                        } 
    }  
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    // // Kirim pesan WhatsApp -> api ke user
                    // try {
                    //     $tickets = Tickets::where('ticket_number', $result[0])->first();
                    //     $user_id = Auth::check() ? Auth::id() : ($result[2] ?? null);
                    //     $user = User::find($user_id);

                    //     if (!$user) {
                    //         \Log::error("User tidak ditemukan untuk user_id: " . ($user_id ?? 'NULL'));
                    //         throw new \Exception("User tidak ditemukan.");
                    //     }

                    //     $rawPhone = $user->mobile ?: $user->phone_number;

                    //     if (!$rawPhone) {
                    //         \Log::warning("Nomor telepon kosong untuk user_id: " . $user->id);
                    //         throw new \Exception("Nomor telepon kosong.");
                    //     }

                    //     $waNumber = $rawPhone[0] == '0' ? '62' . substr($rawPhone, 1) : '62' . preg_replace('/[^0-9]/', '', $rawPhone);

                    //     if (!preg_match('/^62[1-9][0-9]{7,10}$/', $waNumber)) {
                    //         \Log::warning("Nomor WA tidak valid: " . $waNumber);
                    //         throw new \Exception("Nomor WA tidak valid.");
                    //     }

                    //     $priorityObj = \App\Model\helpdesk\Ticket\Ticket_Priority::find($tickets->priority_id);
                    //     $ticket_priority = $priorityObj ? $priorityObj->priority_desc : 'Tidak ada';

                    //     $statusObj = \App\Model\helpdesk\Ticket\Ticket_Status::find($tickets->status);
                    //     $ticket_status = $statusObj ? $statusObj->name : 'Tidak diketahui';

                    //     $message = "📄 Tiket Anda berhasil dibuat!\n" .
                    //         "Nomor Tiket : " . $result[0] . "\n" .
                    //         "Nama        : " . $name . "\n" .
                    //         "Subjek      : $subject\n" .
                    //         "Priority    : $ticket_priority\n" .
                    //         "Pesan       : " . strip_tags($details) . "\n" .
                    //         "Silakan simpan nomor tiket untuk pelacakan lebih lanjut.";

                    //     $response = \Illuminate\Support\Facades\Http::withBasicAuth(env('WA_API_AUTH_USER'), env('WA_API_AUTH_PASS'))
                    //         ->post(env('WA_API_URL'), [
                    //             'phone' => $waNumber . '@s.whatsapp.net',
                    //             'message' => $message,
                    //         ]);

                    //     \Log::info('WA API Response: ' . $response->body());
                    //     \Log::debug("Nomor WA final: " . $waNumber);

                    //     $responseData = $response->json();
                    //     if (!($responseData['status'] ?? false)) {
                    //         \Log::error('Gagal kirim WA: ' . ($responseData['message'] ?? 'Tidak diketahui'));
                    //     }
                    // } catch (\Exception $waException) {
                    //     \Log::error('WA exception: ' . $waException->getMessage());
                    // }




//                    foreach ($attachments as $attachment) {
//                        if ($attachment != null) {
//                            $name = $attachment->getClientOriginalName();
//                            $type = $attachment->getClientOriginalExtension();
//                            $size = $attachment->getSize();
//                            $data = file_get_contents($attachment->getRealPath());
//                            $attachPath = $attachment->getRealPath();
//                            $ta->create(['thread_id' => $thread->id, 'name' => $name, 'size' => $size, 'type' => $type, 'file' => $data, 'poster' => 'ATTACHMENT']);
//                        }
//                    }
                
                // dd($result);
                return Redirect::back()->with('success', Lang::get('lang.Ticket-has-been-created-successfully-your-ticket-number-is').' '.$result[0].'. ');
            } else {
                return Redirect::back()->withInput($request->except('password'))->with('fails', Lang::get('lang.failed-to-create-user-tcket-as-mobile-has-been-taken'));
            }
        } catch (\Exception $ex) {
            return redirect()->back()->with('fails', $ex->getMessage());
        }
//        dd($result);
    }

    /**
     * reply.
     *
     * @param type $value
     *
     * @return type view
     */
    public function post_ticket_reply($id, Request $request)
    {
        try {
            $comment = $request->input('comment');
            if (!empty($comment)) {
                $tickets = Tickets::where('id', '=', $id)->first();
                $thread = Ticket_Thread::where('ticket_id', '=', $tickets->id)->first();

                $subject = $thread->title.'[#'.$tickets->ticket_number.']';
                $body = $comment;

                $user_cred = User::where('id', '=', $tickets->user_id)->first();

                $fromaddress = $user_cred->email;
                $fromname = $user_cred->user_name;
                $phone = '';
                $phonecode = '';
                $mobile_number = '';

                $helptopic = $tickets->help_topic_id;
                $sla = $tickets->sla;
                $priority = $tickets->priority_id;
                $source = $tickets->source;
                $collaborator = '';
                $dept = $tickets->dept_id;
                $assign = $tickets->assigned_to;
                $form_data = null;
                $team_assign = null;
                $ticket_status = null;
                $auto_response = 0;

                $this->TicketWorkflowController->workflow($fromaddress, $fromname, $subject, $body, $phone, $phonecode, $mobile_number, $helptopic, $sla, $priority, $source, $collaborator, $dept, $assign, $team_assign, $ticket_status, $form_data, $auto_response);

                return \Redirect::back()->with('success1', Lang::get('lang.successfully_replied'));
            } else {
                return \Redirect::back()->with('fails1', Lang::get('lang.please_fill_some_data'));
            }
        } catch (Exception $e) {
            return \Redirect::back()->with('fails1', $e->getMessage());
        }
    }

    public function getCustomForm(Request $request)
    {
        $html = '';
        $helptopic_id = $request->input('helptopic');
        $helptopics = new Help_topic();
        $helptopic = $helptopics->find($helptopic_id);
        if (!$helptopic) {
            throw new Exception('We can not find your request');
        }
        $custom_form = $helptopic->custom_form;
        if ($custom_form) {
            $fields = new Fields();
            $forms = new \App\Model\helpdesk\Form\Forms();
            $form_controller = new \App\Http\Controllers\Admin\helpdesk\FormController($fields, $forms);
            $html = $form_controller->renderForm($custom_form);
        }

        return $html;
    }
}
