<?php

namespace Database\Seeders;

use App\Models\Applicant;
use App\Models\ApplicantCertification;
use App\Models\ApplicantEducation;
use App\Models\ApplicantExperience;
use App\Models\ApplicantRequirement;
use App\Models\ApplicantSkill;
use App\Models\ApplicationStatusHistory;
use App\Models\JobPosition;
use App\Models\RequirementType;
use App\Models\User;
use App\Services\FolderCategoryService;
use App\Services\ReferenceCodeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Fifty fictitious applicants for demonstrations, each with a complete profile
 * (personal details, education, work history, skills, certifications and
 * documents) and a working applicant-portal account.
 *
 *   php artisan db:seed --class=DemoApplicantsSeeder
 *
 * They are spread across the stages a record can rest at before training:
 * awaiting the identity check, in screening, with requirements outstanding,
 * waiting on medicals, and ready for deployment. Each stage's documents are the
 * ones that stage implies, and the folder the system derives from them is
 * checked against the status before anything is committed. Nothing goes past
 * "ready for deployment", because training, endorsement and deployment stand
 * for records in other modules, made through those workflows.
 *
 * Every verified, submitted or rejected document has a file behind it: a
 * one-page PDF marked as a placeholder, stored where real uploads go, so it
 * opens from the applicant screen and the portal like any other.
 *
 * Every account's email ends in @example.com, a domain reserved for examples,
 * so nothing sent to these people can reach a real inbox and the whole set is
 * easy to find again. Passwords are random on every run and are written, with
 * the emails, to storage/app/private/demo-applicant-credentials.csv (ignored by
 * git). Running the seeder again leaves existing records as they are and issues
 * those accounts new passwords.
 */
class DemoApplicantsSeeder extends Seeder
{
    /**
     * Fixed, so a fresh database gets the same fifty people at the same stages.
     * The finer details still vary with the day it runs, since ages and dates
     * are counted back from today.
     */
    private const RANDOM_SEED = 20261027;

    private const DOMAIN = 'example.com';

    private const CREDENTIALS_FILE = 'app/private/demo-applicant-credentials.csv';

    /** How many applicants rest at each stage, and the folder each stage implies. */
    private const STAGES = [
        'applied' => [5, 'folder_3'],
        'initial_screening' => [8, 'folder_3'],
        'incomplete_requirements' => [9, 'folder_3'],
        'pending_final_requirements' => [13, 'folder_2'],
        'ready_for_deployment' => [15, 'folder_1'],
    ];

    /** Days ago each stage's applications were made: the further along, the longer ago. */
    private const APPLICATION_WINDOW = [
        'applied' => [1, 6],
        'initial_screening' => [6, 21],
        'incomplete_requirements' => [14, 40],
        'pending_final_requirements' => [25, 60],
        'ready_for_deployment' => [35, 90],
    ];

    private const AUTO_REASON = 'Advanced automatically: document requirements satisfied.';

    /** First name, middle name, last name, suffix, sex. */
    private const PEOPLE = [
        ['Kristine Joy', 'Mercado', 'Villanueva', null, 'female'],
        ['Rowena', 'Bautista', 'Castillo', null, 'female'],
        ['Jessa Mae', 'Ramos', 'Aquino', null, 'female'],
        ['Princess', 'Lopez', 'Fernandez', null, 'female'],
        ['Lovely', 'Garcia', 'Manalo', null, 'female'],
        ['Mary Grace', 'Dizon', 'Pascual', null, 'female'],
        ['Shaira', 'Navarro', 'Salazar', null, 'female'],
        ['Rhea', 'Morales', 'Cruz', null, 'female'],
        ['Jonalyn', 'Tolentino', 'De Guzman', null, 'female'],
        ['Camille', 'Reyes', 'Mendoza', null, 'female'],
        ['Analyn', 'Soriano', 'Gonzales', null, 'female'],
        ['Rochelle', 'Valdez', 'Panganiban', null, 'female'],
        ['Hazel Ann', 'Domingo', 'Torres', null, 'female'],
        ['Marites', 'Lacson', 'Ocampo', null, 'female'],
        ['Clarissa', 'Bernardo', 'Ilagan', null, 'female'],
        ['Joanna Marie', 'Santiago', 'Rivera', null, 'female'],
        ['Erlinda', 'Magbanua', 'Cortez', null, 'female'],
        ['Nicole', 'Javier', 'Perez', null, 'female'],
        ['Divina', 'Abad', 'Marasigan', null, 'female'],
        ['Sheila Mae', 'Carpio', 'Briones', null, 'female'],
        ['Rosalie', 'Medina', 'Saldivar', null, 'female'],
        ['Aileen', 'Ferrer', 'Manalastas', null, 'female'],
        ['Cherry Ann', 'Gutierrez', 'Belen', null, 'female'],
        ['Liezel', 'Umali', 'Dimaculangan', null, 'female'],
        ['Mark Anthony', 'Cruz', 'Reyes', null, 'male'],
        ['John Paul', 'Mendoza', 'Garcia', null, 'male'],
        ['Jerome', 'Aguilar', 'Santos', null, 'male'],
        ['Ronald', 'Pascual', 'Dela Cruz', null, 'male'],
        ['Christian', 'Villanueva', 'Bautista', null, 'male'],
        ['Rommel', 'Salvador', 'Hernandez', null, 'male'],
        ['Jayson', 'Ramos', 'Mercado', null, 'male'],
        ['Kenneth', 'De Leon', 'Flores', null, 'male'],
        ['Arnel', 'Castro', 'Manalo', null, 'male'],
        ['Jericho', 'Navarro', 'Alcantara', null, 'male'],
        ['Reynaldo', 'Fajardo', 'Tamayo', 'Jr.', 'male'],
        ['Michael', 'Robles', 'Sison', null, 'male'],
        ['Edgardo', 'Bañez', 'Lualhati', null, 'male'],
        ['Nestor', 'Agustin', 'Macaraeg', null, 'male'],
        ['Paolo', 'Enriquez', 'Lim', null, 'male'],
        ['Rodel', 'Samonte', 'Panganiban', null, 'male'],
        ['Dennis', 'Catapang', 'Ramirez', null, 'male'],
        ['Joel', 'Batungbakal', 'Ignacio', null, 'male'],
        ['Ariel', 'Mangubat', 'Concepcion', null, 'male'],
        ['Vincent', 'Esguerra', 'Dalisay', null, 'male'],
        ['Bryan', 'Caballero', 'Pineda', null, 'male'],
        ['Gilbert', 'Ordoñez', 'Malabanan', null, 'male'],
        ['Jomar', 'Velasco', 'Andaya', null, 'male'],
        ['Renato', 'Gatchalian', 'Peña', null, 'male'],
        ['Allan', 'Tiongson', 'Malicdem', null, 'male'],
        ['Ramil', 'Buenaventura', 'Coronel', null, 'male'],
    ];

    /**
     * Towns around the agency in Sta. Cruz, Laguna: barangays, the distance
     * range in kilometres to a Sta. Cruz worksite, and how often applicants
     * come from there.
     */
    private const TOWNS = [
        ['Sta. Cruz', ['Bagumbayan', 'Bubukal', 'Calios', 'Duhat', 'Gatid', 'Labuin', 'Oogong', 'Pagsawitan', 'Patimbao', 'Santisima Cruz'], [1, 7], 30],
        ['Pagsanjan', ['Anibong', 'Buboy', 'Calusiche', 'Dingin', 'Lambac', 'Maulawin', 'Pinagsanjan', 'Sabang'], [4, 9], 12],
        ['Pila', ['Bagong Pook', 'Bulilan Norte', 'Concepcion', 'Linga', 'Masico', 'Pansol', 'Santa Clara Sur'], [6, 11], 10],
        ['Lumban', ['Bagong Silang', 'Caliraya', 'Lewin', 'Maracta', 'Primera Parang', 'Salac', 'Wawa'], [7, 13], 8],
        ['Magdalena', ['Alipit', 'Baanan', 'Balanac', 'Bucal', 'Ibabang Butnong', 'Maravilla', 'Poblacion'], [8, 13], 7],
        ['Victoria', ['Banca-banca', 'Daniw', 'Masapang', 'Nanhaya', 'San Benito', 'San Roque'], [10, 15], 7],
        ['Nagcarlan', ['Alibungbungan', 'Bayaquitos', 'Buhanginan', 'Calumpang', 'Lazaan', 'Talangan'], [11, 17], 6],
        ['Liliw', ['Bagong Anyo', 'Bungkol', 'Dita', 'Ibabang San Roque', 'Kanlurang Bukal', 'Novaliches'], [14, 19], 5],
        ['Calauan', ['Balayhangin', 'Bangyas', 'Dayap', 'Hanggan', 'Imok', 'Masiit', 'Prinza'], [16, 22], 5],
        ['Paete', ['Bagumbayan', 'Ibaba del Norte', 'Ibaba del Sur', 'Ilaya del Norte', 'Maytoong', 'Quinale'], [17, 22], 4],
        ['Bay', ['Bitin', 'Calo', 'Dila', 'Maitim', 'Masaya', 'Paciano Rizal', 'Puypuy', 'Tagumpay'], [24, 30], 3],
        ['Calamba', ['Bucal', 'Halang', 'Lecheria', 'Mayapa', 'Parian', 'Real', 'Turbina'], [42, 50], 3],
    ];

    /** Home provinces of applicants who moved to Laguna for work. */
    private const PROVINCIAL = [
        'Brgy. San Isidro, Tiaong, Quezon',
        'Brgy. Bagong Silang, Lucena City, Quezon',
        'Brgy. San Roque, Infanta, Quezon',
        'Brgy. Poblacion, Catbalogan City, Samar',
        'Brgy. Sto. Niño, Tacloban City, Leyte',
        'Brgy. San Jose, Rosario, Batangas',
        'Brgy. Mabini, Daet, Camarines Norte',
        'Brgy. Poblacion, Iriga City, Camarines Sur',
        'Brgy. Libertad, Boac, Marinduque',
        'Brgy. Del Pilar, Calapan City, Oriental Mindoro',
    ];

    private const STREETS = ['Rizal St.', 'Mabini St.', 'Bonifacio St.', 'Luna St.', 'P. Burgos St.', 'Gen. Malvar St.', 'Quezon Ave.', 'National Highway'];

    /** How often each position is preferred, by its title. */
    private const POSITION_WEIGHTS = [
        'Production Helper' => 12,
        'Packaging Staff' => 8,
        'Warehouse Staff' => 7,
        'Machine Operator' => 6,
        'Quality Control Inspector' => 5,
        'Utility / Sanitation Staff' => 4,
        'Helper' => 4,
        'General Worker' => 4,
    ];

    /** Skills an applicant for each position plausibly lists. */
    private const SKILLS = [
        'Production Helper' => ['Food Handling', 'GMP Compliance', 'Manual Material Handling', 'Packaging', 'Machine Operation'],
        'Packaging Staff' => ['Packaging', 'Labeling', 'Food Handling', 'Quality Inspection', 'GMP Compliance'],
        'Warehouse Staff' => ['Inventory Management', 'Forklift Operation', 'Manual Material Handling', 'Stockroom Management', 'Basic Computer Skills'],
        'Machine Operator' => ['Machine Operation', 'Basic Electrical', 'Preventive Maintenance', 'Welding'],
        'Quality Control Inspector' => ['Quality Inspection', 'GMP Compliance', 'Food Safety Documentation', 'Basic Computer Skills'],
        'Utility / Sanitation Staff' => ['Sanitation', 'Housekeeping', 'Chemical Handling', 'Waste Segregation'],
        'Helper' => ['Manual Material Handling', 'Sanitation', 'Packaging', 'Stock Replenishment'],
        'General Worker' => ['Manual Material Handling', 'Packaging', 'Sanitation', 'Customer Service'],
    ];

    /** TESDA qualifications that fit each position. */
    private const CERTIFICATIONS = [
        'Production Helper' => ['Food Handling NC II', 'Food Safety NC II', 'Food Processing NC II'],
        'Packaging Staff' => ['Food Handling NC II', 'Food Safety NC II', 'Food Processing NC II'],
        'Warehouse Staff' => ['Heavy Equipment Operation (Forklift) NC II', 'Driving NC II'],
        'Machine Operator' => ['Shielded Metal Arc Welding (SMAW) NC II', 'Electrical Installation and Maintenance NC II', 'Mechatronics Servicing NC II'],
        'Quality Control Inspector' => ['Food Safety NC II', 'Food Processing NC II'],
        'Utility / Sanitation Staff' => ['Housekeeping NC II'],
        'Helper' => ['Housekeeping NC II', 'Food Handling NC II'],
        'General Worker' => ['Housekeeping NC II', 'Bread and Pastry Production NC II'],
    ];

    private const PAST_JOBS = [
        'Production Operator' => 'Operated filling and sealing lines; recorded hourly output and reported line stoppages.',
        'Packer' => 'Packed and labelled finished goods to order; checked seals and expiry coding.',
        'Warehouse Helper' => 'Loaded and unloaded deliveries; kept stock bins in first-in, first-out order.',
        'Machine Operator' => 'Ran and adjusted extrusion machines; did daily lubrication and cleaning.',
        'Quality Checker' => 'Inspected samples against specifications; logged rejects and hold tags.',
        'Utility Worker' => 'Cleaned and sanitised production areas on schedule; segregated waste.',
        'Service Crew' => 'Prepared orders and handled the counter during peak hours.',
        'Sales Clerk' => 'Assisted customers, replenished shelves and counted inventory weekly.',
        'Delivery Helper' => 'Assisted the driver on deliveries; checked quantities against delivery receipts.',
        'Stock Clerk' => 'Received stock, encoded receipts and prepared requisition slips.',
    ];

    private const COMPANIES = [
        'Golden Harvest Foods Inc.', 'Laguna Packaging Corp.', 'Lakeshore Bottling Co.', 'Mabuhay Snack Foods Corp.',
        'Southern Tagalog Plastics Inc.', 'Calamba Precision Metals Inc.', 'Sta. Cruz Rice Mill', 'Pagsanjan Agri-Ventures',
        'Bay Logistics Services', 'Lagunense Supermart', 'Victoria Poultry Farms', 'Rizal Avenue Hardware',
        'Sampaguita Garments Inc.', 'Laguna Lake Fish Processing',
    ];

    private const COLLEGES = [
        'Laguna State Polytechnic University - Sta. Cruz Campus',
        'Laguna State Polytechnic University - Los Baños Campus',
        'Laguna State Polytechnic University - San Pablo City Campus',
        'Laguna State Polytechnic University - Siniloan Campus',
        'Union College of Laguna',
    ];

    private const COURSES = [
        'BS Food Technology', 'BS Industrial Technology major in Food Technology',
        'BS Business Administration major in Operations Management', 'BS Information Technology',
        'BS Hospitality Management', 'BS Office Administration', 'BS Agriculture',
    ];

    private const STRANDS = [
        'TVL - Food Processing', 'TVL - Electrical Installation and Maintenance', 'TVL - Home Economics',
        'TVL - Information and Communications Technology', 'Accountancy, Business and Management (ABM)',
        'Humanities and Social Sciences (HUMSS)', 'General Academic Strand (GAS)',
    ];

    private const TRAINING_CENTRES = ['TESDA Provincial Training Center - Sta. Cruz', 'Laguna Technical Institute', 'Mabuhay Skills Training Center'];

    private const VOCATIONAL_PROGRAMS = [
        'Food Processing NC II', 'Electrical Installation and Maintenance NC II', 'Shielded Metal Arc Welding (SMAW) NC II',
        'Housekeeping NC II', 'Bread and Pastry Production NC II', 'Computer Systems Servicing NC II',
    ];

    private const REMARKS = [
        'Willing to work night shift.',
        'Prefers an assignment near Sta. Cruz.',
        'Has own transportation (motorcycle).',
        'Referred by a current CDE worker.',
        'Available for weekend overtime.',
        'Previously applied in 2025; did not complete requirements then.',
    ];

    private const REJECTION_REASONS = [
        'The scan is blurred; the name and dates cannot be read. Please upload a clearer copy.',
        'Only the front page was submitted. Please include the back page.',
        'The name on the document does not match the application. Please bring the original to the office.',
    ];

    private const PASSWORD_WORDS = [
        'Mango', 'Narra', 'Pandan', 'Banaba', 'Molave', 'Kamagong', 'Calamansi', 'Rambutan', 'Lanzones', 'Santol',
        'Tamaraw', 'Lawin', 'Malunggay', 'Bibingka', 'Dalandan', 'Kalesa', 'Bayanihan', 'Sampaguita', 'Yakal', 'Pili',
    ];

    private User $hr;

    private string $disk;

    /** @var Collection<string, RequirementType> */
    private Collection $types;

    /** @var array<string, true> */
    private array $usedPhones = [];

    private int $expiringSoonLeft = 3;

    public function run(): void
    {
        $this->hr = User::where('email', 'hr@cdemanpower.local')->firstOrFail();
        $this->disk = config('empower.uploads.disk');
        $this->types = RequirementType::active()->orderBy('display_order')->get()->keyBy('requirement_code');
        $positions = JobPosition::openForApplication()->get()->keyBy('position_title');

        $codes = app(ReferenceCodeService::class);
        $folders = app(FolderCategoryService::class);

        mt_srand(self::RANDOM_SEED);

        $stages = [];
        foreach (self::STAGES as $stage => [$count]) {
            array_push($stages, ...array_fill(0, $count, $stage));
        }
        $stages = $this->shuffled($stages);

        if (count($stages) !== count(self::PEOPLE)) {
            throw new RuntimeException('The stage counts must add up to the number of people.');
        }

        $credentials = [];

        foreach (self::PEOPLE as $index => $person) {
            $email = $this->emailFor($person);
            $password = $this->password();
            $account = User::where('email', $email)->first();

            if ($account) {
                $account->forceFill(['password' => Hash::make($password), 'must_change_password' => false])->save();
                $credentials[] = $this->credentialRow($account->applicant, $email, $password);
                $this->command?->line("  kept {$email} (existing; password reissued)");

                continue;
            }

            $applicant = $this->seedApplicant($person, $stages[$index], $email, $password, $positions, $codes, $folders);
            $credentials[] = $this->credentialRow($applicant, $email, $password);
        }

        // Back to unpredictable randomness for anything that runs after this.
        mt_srand();

        $this->writeCredentials($credentials);
    }

    private function seedApplicant(
        array $person,
        string $stage,
        string $email,
        string $password,
        Collection $positions,
        ReferenceCodeService $codes,
        FolderCategoryService $folders,
    ): Applicant {
        [$first, $middle, $last, $suffix, $sex] = $person;

        $now = CarbonImmutable::now();
        $latest = $now->subMinutes(30);
        $today = $now->startOfDay();

        [$daysLo, $daysHi] = self::APPLICATION_WINDOW[$stage];
        $created = $this->between($now->subDays($daysHi), $now->subDays($daysLo));

        $channel = $stage === 'applied'
            ? 'online'
            : $this->weighted(['online' => 35, 'walk_in' => 45, 'messenger' => 12, 'email' => 8]);
        $online = $channel === 'online';

        // Mostly within the 18-35 bracket clients ask for, with some outside it
        // so the age filter has someone to filter.
        $age = $this->chance(82) ? mt_rand(19, 35) : mt_rand(36, 42);
        $birth = $today->subYears($age)->subDays(mt_rand(0, 360));
        $civil = $age < 23
            ? 'single'
            : $this->weighted(['single' => 64, 'married' => 30, 'separated' => 3, 'widowed' => 3]);

        $town = $this->weightedRow(self::TOWNS, 3);
        [$townName, $barangays, [$distanceLo, $distanceHi]] = $town;
        $barangay = $this->pick($barangays);
        $present = $this->chance(50)
            ? sprintf('Purok %d, Brgy. %s, %s, Laguna', mt_rand(1, 7), $barangay, $townName)
            : sprintf('%d %s, Brgy. %s, %s, Laguna', mt_rand(3, 480), $this->pick(self::STREETS), $barangay, $townName);

        $positionTitle = $this->weighted(self::POSITION_WEIGHTS);
        $position = $positions->get($positionTitle);

        /*
         * The timeline. Online registrants come to the office a few days after
         * registering, where their identity is checked and screening begins;
         * everyone else is screened as HR enters them.
         */
        $screenedAt = null;
        $incompleteAt = null;
        $primaryAt = null;
        $finalAt = null;

        if ($stage !== 'applied') {
            $screenedAt = $online
                ? $this->between($created->addDay(), $created->addDays(4))
                : $created->addMinutes(mt_rand(3, 15));
        }

        $viaIncomplete = $stage === 'incomplete_requirements'
            || (in_array($stage, ['pending_final_requirements', 'ready_for_deployment'], true) && $this->chance(40));

        if ($viaIncomplete) {
            $incompleteAt = $this->between($screenedAt->addDay(), $this->earliest($screenedAt->addDays(5), $latest));
        }

        if ($stage === 'pending_final_requirements') {
            $primaryAt = $this->between(($incompleteAt ?? $screenedAt)->addDays(2), $latest->subDay());
        }

        if ($stage === 'ready_for_deployment') {
            $primaryAt = $this->between(($incompleteAt ?? $screenedAt)->addDays(2), $now->subDays(8));
            $finalAt = $this->between($primaryAt->addDays(3), $latest);
        }

        $profile = $this->qualifications($positionTitle, $birth, $created, $townName);
        $documents = $this->documentPlan($stage, $created, $screenedAt, $primaryAt, $finalAt, $latest, $civil, $profile['experiences'] !== []);

        $interviewed = $stage !== 'applied';

        $attributes = [
            'applicant_code' => $codes->applicant(),
            'source_channel' => $channel,
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'suffix' => $suffix,
            'sex' => $sex,
            'birth_date' => $birth->toDateString(),
            'civil_status' => $civil,
            'nationality' => 'Filipino',
            'height_cm' => $sex === 'male' ? mt_rand(158, 180) : mt_rand(147, 167),
            'weight_kg' => $sex === 'male' ? mt_rand(55, 82) : mt_rand(44, 68),
            'contact_number' => $this->phone(),
            'email' => $email,
            'present_address' => $present,
            'provincial_address' => $this->chance(35) ? $this->pick(self::PROVINCIAL) : $present,
            'preferred_position' => $position?->position_title ?? $positionTitle,
            'preferred_position_id' => $position?->id,
            'availability_date' => $stage === 'ready_for_deployment'
                ? $today->addDays(mt_rand(-5, 7))->toDateString()
                : $today->addDays(mt_rand(0, 30))->toDateString(),
            'distance_km' => mt_rand($distanceLo * 10, $distanceHi * 10) / 10,
            // Ratings come from the interview at the office, which an applicant
            // still awaiting the identity check has not had.
            'communication_rating' => $interviewed ? $this->weighted([5 => 20, 4 => 40, 3 => 30, 2 => 10]) : null,
            'reliability_rating' => $interviewed ? $this->weighted([5 => 25, 4 => 40, 3 => 28, 2 => 7]) : null,
            'application_date' => $created->toDateString(),
            'remarks' => $interviewed && $this->chance(40) ? $this->pick(self::REMARKS) : null,
            'created_by' => $online ? null : $this->hr->id,
        ];

        $written = [];

        try {
            return DB::transaction(function () use (
                $attributes, $stage, $online, $created, $screenedAt, $incompleteAt, $primaryAt, $finalAt,
                $profile, $documents, $email, $password, $folders, $townName, $barangay, &$written,
            ) {
                $applicant = new Applicant($attributes);
                $applicant->forceFill([
                    'current_status' => 'applied',
                    'self_registered_at' => $online ? $created : null,
                    'identity_verified_at' => $online && $screenedAt ? $screenedAt : null,
                    'identity_verified_by' => $online && $screenedAt ? $this->hr->id : null,
                    'created_at' => $created,
                    'updated_at' => $created,
                ])->save();

                $this->saveQualifications($applicant, $profile, $created);
                $lastActivity = $this->saveDocuments($applicant, $stage, $documents, $created, $townName, $barangay, $written);

                $history = $this->history($stage, $online, $screenedAt, $incompleteAt, $primaryAt, $finalAt);
                foreach ($history as [$from, $to, $reason, $at]) {
                    (new ApplicationStatusHistory)->forceFill([
                        'applicant_id' => $applicant->id,
                        'from_status' => $from,
                        'to_status' => $to,
                        'reason' => $reason,
                        'changed_by' => $this->hr->id,
                        'changed_at' => $at,
                        'created_at' => $at,
                        'updated_at' => $at,
                    ])->save();
                    $lastActivity = $this->latestOf($lastActivity, $at);
                }

                /*
                 * The folder is derived exactly as the system derives it, then
                 * checked against the stage. A mismatch would be a status the
                 * documents do not support, which is the inconsistency the
                 * lifecycle gates exist to prevent.
                 */
                $folder = $folders->recalculate($applicant);
                $expected = self::STAGES[$stage][1];

                if ($folder !== $expected) {
                    throw new RuntimeException("{$applicant->full_name}: documents give {$folder}, but {$stage} needs {$expected}.");
                }

                $applicant->forceFill(['current_status' => $stage, 'updated_at' => $lastActivity])->save();

                $this->createPortalAccount($applicant, $email, $password, $online ? $created : $screenedAt->addMinutes(mt_rand(5, 30)));

                return $applicant;
            });
        } catch (Throwable $exception) {
            // The rows rolled back; the files written for them did not.
            foreach ($written as $path) {
                Storage::disk($this->disk)->delete($path);
            }

            throw $exception;
        }
    }

    /**
     * The status history, as the lifecycle service would have recorded it.
     *
     * @return list<array{0: ?string, 1: string, 2: ?string, 3: CarbonImmutable}>
     */
    private function history(
        string $stage,
        bool $online,
        ?CarbonImmutable $screenedAt,
        ?CarbonImmutable $incompleteAt,
        ?CarbonImmutable $primaryAt,
        ?CarbonImmutable $finalAt,
    ): array {
        if ($stage === 'applied') {
            return [];
        }

        $rows = [[
            'applied', 'initial_screening',
            $online ? 'Identity confirmed at the office.' : 'Applicant registered at the office.',
            $screenedAt,
        ]];
        $current = 'initial_screening';

        if ($incompleteAt) {
            $rows[] = [$current, 'incomplete_requirements', 'Some primary requirements are still outstanding.', $incompleteAt];
            $current = 'incomplete_requirements';
        }

        if ($primaryAt) {
            $rows[] = [$current, 'primary_requirements_complete', self::AUTO_REASON, $primaryAt];
            $rows[] = ['primary_requirements_complete', 'pending_final_requirements', self::AUTO_REASON, $primaryAt->addSecond()];
        }

        if ($finalAt) {
            $rows[] = ['pending_final_requirements', 'ready_for_deployment', self::AUTO_REASON, $finalAt];
        }

        return $rows;
    }

    /**
     * Which documents are where, and when.
     *
     * @return array<string, array{status: string, at: CarbonImmutable, viewed?: bool, reason?: string}>
     */
    private function documentPlan(
        string $stage,
        CarbonImmutable $created,
        ?CarbonImmutable $screenedAt,
        ?CarbonImmutable $primaryAt,
        ?CarbonImmutable $finalAt,
        CarbonImmutable $latest,
        string $civil,
        bool $hasExperience,
    ): array {
        $required = fn (string $group) => $this->types
            ->filter(fn (RequirementType $t) => $t->requirement_group === $group && $t->is_required)
            ->keys()->all();

        $primary = $required('primary');
        $final = $required('final');
        $others = array_values(array_diff($primary, ['resume']));
        $plan = [];

        $verify = function (array $codes, CarbonImmutable $from, CarbonImmutable $to, bool $lastAtEnd = false) use (&$plan) {
            foreach ($codes as $code) {
                $plan[$code] = ['status' => 'verified', 'at' => $this->between($from, $to)];
            }
            // The step that completed a group happened when its last document
            // was verified, so one of them is verified at exactly that moment.
            if ($lastAtEnd && $codes !== []) {
                $plan[$this->pick($codes)]['at'] = $to;
            }
        };

        $submit = function (array $codes, CarbonImmutable $from, CarbonImmutable $to, int $viewedChance) use (&$plan) {
            foreach ($codes as $code) {
                $plan[$code] = ['status' => 'submitted', 'at' => $this->between($from, $to), 'viewed' => $this->chance($viewedChance)];
            }
        };

        switch ($stage) {
            case 'applied':
                // Uploaded through the portal right after registering; nobody
                // has reviewed them, since the applicant has not been in yet.
                $submit(['resume'], $created, $latest, 0);
                $submit($this->sample(array_intersect(['id_photo', 'birth_certificate', 'bio_data', 'diploma', 'barangay_clearance'], $primary), mt_rand(1, 3)), $created, $latest, 0);
                break;

            case 'initial_screening':
                $verified = $this->sample($others, mt_rand(1, 4));
                $verify(['resume', ...$verified], $screenedAt, $latest);
                $submit($this->sample(array_diff($others, $verified), mt_rand(0, 2)), $screenedAt, $latest, 50);
                break;

            case 'incomplete_requirements':
                $verified = $this->sample($others, mt_rand(5, count($others) - 2));
                $verify(['resume', ...$verified], $screenedAt, $latest);
                $outstanding = $this->shuffled(array_values(array_diff($others, $verified)));
                $rejected = array_shift($outstanding);
                $plan[$rejected] = [
                    'status' => 'rejected',
                    'at' => $this->between($screenedAt->addHours(2), $latest),
                    'reason' => $this->pick(self::REJECTION_REASONS),
                ];
                $submit(array_filter($outstanding, fn () => $this->chance(50)), $screenedAt, $latest, 50);
                if ($hasExperience) {
                    $this->chance(60) ? $verify(['coe'], $screenedAt, $latest) : $submit(['coe'], $screenedAt, $latest, 0);
                }
                break;

            case 'pending_final_requirements':
            case 'ready_for_deployment':
                $verify($primary, $screenedAt, $primaryAt, true);

                if ($hasExperience) {
                    $verify(['coe'], $screenedAt, $primaryAt);
                }
                if ($civil === 'married') {
                    $verify(['marriage_certificate'], $screenedAt, $primaryAt);
                }

                if ($stage === 'ready_for_deployment') {
                    $verify($final, $primaryAt, $finalAt, true);
                } else {
                    $finals = $this->shuffled($final);
                    $verify(array_splice($finals, 0, mt_rand(0, 3)), $primaryAt, $latest);
                    $submit(array_splice($finals, 0, mt_rand(0, 2)), $primaryAt, $latest, 50);
                }
                break;
        }

        return $plan;
    }

    private function saveDocuments(
        Applicant $applicant,
        string $stage,
        array $plan,
        CarbonImmutable $created,
        string $town,
        string $barangay,
        array &$written,
    ): CarbonImmutable {
        $today = CarbonImmutable::today();
        $lastActivity = $created;

        foreach ($this->types as $code => $type) {
            $entry = $plan[$code] ?? null;
            $row = [
                'applicant_id' => $applicant->id,
                'requirement_type_id' => $type->id,
                'status' => 'missing',
                'created_at' => $created,
                'updated_at' => $created,
            ];

            if ($entry) {
                $at = $entry['at'];
                $status = $entry['status'];

                // Verified and rejected documents were uploaded a while before
                // the officer reached them; a submitted one is still waiting.
                $submittedAt = $status === 'submitted'
                    ? $at
                    : $this->latestOf($created, $at->subHours(mt_rand(2, 48)));
                $viewedAt = match (true) {
                    $status !== 'submitted' => $this->between($submittedAt, $at),
                    $entry['viewed'] ?? false => $this->between($submittedAt, $this->latestOf($submittedAt, CarbonImmutable::now()->subMinutes(10))),
                    default => null,
                };

                /*
                 * Clearances and medicals stay valid for months. A few
                 * deployment-ready applicants have a police clearance running
                 * out within three weeks, so the expiry warnings have
                 * something to show; none has lapsed, which would drop the
                 * applicant out of Folder 1.
                 */
                $expiry = null;
                if ($type->has_expiry) {
                    $expiringSoon = $status === 'verified' && $code === 'police_clearance'
                        && $stage === 'ready_for_deployment' && $this->expiringSoonLeft > 0 && $this->chance(30);

                    if ($expiringSoon) {
                        $this->expiringSoonLeft--;
                    }

                    $expiry = $expiringSoon
                        ? $today->addDays(mt_rand(8, 20))
                        : $this->latestOf($today->addDays(30), $at->addDays(mt_rand(150, 330)));
                }

                [$method, $note] = $status === 'verified' ? $this->verificationMethod($applicant->source_channel) : [null, null];

                $file = $this->storeDocument($applicant, $type, $submittedAt, $expiry, $town, $barangay);
                $written[] = $file['file_path'];

                $row = array_merge($row, $file, [
                    'status' => $status,
                    'submitted_at' => $submittedAt,
                    'first_viewed_at' => $viewedAt,
                    'first_viewed_by' => $viewedAt ? $this->hr->id : null,
                    'verified_at' => $status === 'verified' ? $at : null,
                    'verified_by' => $status === 'verified' ? $this->hr->id : null,
                    'verification_method' => $method,
                    'verification_note' => $note,
                    'expiry_date' => $expiry?->toDateString(),
                    'rejection_reason' => $entry['reason'] ?? null,
                    'updated_at' => $at,
                ]);

                $lastActivity = $this->latestOf($lastActivity, $at);
            }

            (new ApplicantRequirement)->forceFill($row)->save();
        }

        return $lastActivity;
    }

    /** @return array{0: string, 1: ?string} */
    private function verificationMethod(string $channel): array
    {
        return match ($channel) {
            'online' => $this->chance(75) ? ['online_upload', null] : ['walk_in', 'Original sighted at the counter; scan on file.'],
            'messenger', 'email' => $this->chance(60)
                ? ['other', 'Received through '.($channel === 'email' ? 'email' : 'Messenger').'; original sighted on the first office visit.']
                : ['walk_in', null],
            default => ['walk_in', $this->chance(25) ? 'Original sighted at the counter; photocopy on file.' : null],
        };
    }

    /**
     * Writes a one-page placeholder PDF for a document to the document disk,
     * at the same kind of key a real upload gets.
     *
     * @return array{file_path: string, file_name: string, file_mime: string, file_size_bytes: int, file_hash: string}
     */
    private function storeDocument(
        Applicant $applicant,
        RequirementType $type,
        CarbonImmutable $issuedAt,
        ?CarbonImmutable $expiry,
        string $town,
        string $barangay,
    ): array {
        $lines = [
            'Name: '.$applicant->full_name,
            'Applicant code: '.$applicant->applicant_code,
            'Date of birth: '.$applicant->birth_date->format('F j, Y'),
            'Address: '.$applicant->present_address,
            ...$this->documentDetails($type->requirement_code, $applicant, $town, $barangay),
            'Date issued: '.$issuedAt->format('F j, Y'),
        ];

        if ($expiry) {
            $lines[] = 'Valid until: '.$expiry->format('F j, Y');
        }

        $pdf = $this->placeholderPdf($type->requirement_name, $lines, $type->requirement_code === 'id_photo');
        $key = sprintf('requirements/%d/%s.pdf', $applicant->id, Str::uuid());

        if (! Storage::disk($this->disk)->put($key, $pdf, ['visibility' => 'private'])) {
            throw new RuntimeException("Could not write {$key} to the {$this->disk} disk.");
        }

        return [
            'file_path' => $key,
            'file_name' => Str::slug($type->requirement_name).'-'.Str::slug($applicant->last_name).'.pdf',
            'file_mime' => 'application/pdf',
            'file_size_bytes' => strlen($pdf),
            'file_hash' => hash('sha256', $pdf),
        ];
    }

    /** @return list<string> */
    private function documentDetails(string $code, Applicant $applicant, string $town, string $barangay): array
    {
        return match ($code) {
            'resume' => ['Position sought: '.$applicant->preferred_position, 'Contact: '.$applicant->contact_number.'  /  '.$applicant->email],
            'bio_data' => ['Civil status: '.ucfirst($applicant->civil_status), 'Height / weight: '.(int) $applicant->height_cm.' cm  /  '.(int) $applicant->weight_kg.' kg'],
            'birth_certificate' => ['Sex: '.ucfirst($applicant->sex), 'Registry number: '.$this->digits(4).'-'.$this->digits(6)],
            'diploma' => ['Conferred for completing the course of study prescribed by the school.'],
            'sss' => ['SS number: '.$this->digits(2).'-'.$this->digits(7).'-'.$this->digits(1)],
            'philhealth' => ['PhilHealth number: '.$this->digits(2).'-'.$this->digits(9).'-'.$this->digits(1)],
            'pagibig' => ['Pag-IBIG MID number: '.$this->digits(4).'-'.$this->digits(4).'-'.$this->digits(4)],
            'police_clearance' => ['Clearance number: PCN-'.$this->digits(10), 'Remarks: No derogatory record'],
            'barangay_clearance' => ['Issued by: Brgy. '.$barangay.', '.$town.', Laguna', 'Purpose: Employment'],
            'coe' => ['Certifies employment as stated in the applicant\'s work history.'],
            'marriage_certificate' => ['Registry number: '.$this->digits(4).'-'.$this->digits(5)],
            'id_photo' => ['Recent 2x2 photograph, white background.'],
            'drug_test' => ['Result: NEGATIVE for methamphetamine and THC'],
            'urine_test', 'stool_test' => ['Result: Normal findings'],
            'hepatitis_b' => ['HBsAg screening: NON-REACTIVE'],
            'health_card' => ['Health card number: HC-'.$this->digits(6), 'Issued by: Municipal Health Office, '.$town],
            'medical_result' => ['Classification: Class A - Fit to work'],
            default => [],
        };
    }

    /**
     * A minimal, valid single-page PDF in the built-in Helvetica fonts.
     *
     * @param  list<string>  $lines
     */
    private function placeholderPdf(string $title, array $lines, bool $photoBox): string
    {
        $text = fn (string $value) => str_replace(
            ['\\', '(', ')'],
            ['\\\\', '\\(', '\\)'],
            mb_convert_encoding($value, 'Windows-1252', 'UTF-8')
        );

        $ops = [
            '0.137 0.294 0.608 RG 1.5 w 54 54 504 684 re S',
            'BT /F2 9 Tf 0.39 0.45 0.55 rg 72 708 Td ('.$text('CDE MANPOWER SERVICES  |  DEMONSTRATION RECORD').') Tj ET',
            'BT /F2 22 Tf 0.06 0.13 0.23 rg 72 670 Td ('.$text($title).') Tj ET',
        ];

        $y = 628;
        foreach ($lines as $line) {
            $ops[] = 'BT /F1 11 Tf 0.06 0.13 0.23 rg 72 '.$y.' Td ('.$text($line).') Tj ET';
            $y -= 22;
        }

        if ($photoBox) {
            $ops[] = '0.88 0.91 0.95 rg 72 '.($y - 160).' 144 144 re f';
            $ops[] = 'BT /F2 12 Tf 0.39 0.45 0.55 rg 112 '.($y - 92).' Td (2 x 2) Tj ET';
        }

        $ops[] = 'BT /F1 8 Tf 0.39 0.45 0.55 rg 72 72 Td ('.$text('Placeholder generated for demonstration and testing. It is not a real document and identifies no real person.').') Tj ET';
        $stream = implode("\n", $ops);

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$body."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n".'0000000000 65535 f '."\n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }
        $pdf .= 'trailer'."\n".'<< /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\n".'startxref'."\n".$xref."\n".'%%EOF'."\n";

        return $pdf;
    }

    /**
     * Education, work history, skills and certifications that fit the
     * person's age and the position they want.
     */
    private function qualifications(string $position, CarbonImmutable $birth, CarbonImmutable $created, string $town): array
    {
        $year = (int) $created->format('Y');
        $born = (int) $birth->format('Y');
        $age = $birth->diffInYears($created, true);
        $hometownSchool = $town === 'Sta. Cruz' ? 'Pedro Guevara Memorial National High School' : $town.' National High School';

        // K-12 senior high school only exists for those who finished school
        // from 2018; anyone older finished the four-year high school.
        $secondary = $born >= 2000
            ? ['senior_high_school', $hometownSchool, $this->pick(self::STRANDS), $born + 18]
            : ['high_school', $hometownSchool, null, $born + 16];

        $level = $this->weighted([
            'secondary' => 44, 'vocational' => 16, 'college_undergraduate' => 18, 'college_graduate' => 20, 'elementary' => 2,
        ]);
        if ($level === 'college_graduate' && $age < 22) {
            $level = 'college_undergraduate';
        }

        $educations = match ($level) {
            'elementary' => [['elementary', $town.' Central Elementary School', null, $born + 12, null]],
            'secondary' => [[...$secondary, $this->chance(15) ? 'With Honors' : null]],
            'vocational' => [
                ['vocational', $this->pick(self::TRAINING_CENTRES), $this->pick(self::VOCATIONAL_PROGRAMS), min($year, $secondary[3] + mt_rand(1, 4)), null],
                [...$secondary, null],
            ],
            'college_undergraduate' => [
                ['college_undergraduate', $this->pick(self::COLLEGES), $this->pick(self::COURSES).' ('.$this->pick(['2nd', '3rd']).' year)', null, null],
                [...$secondary, null],
            ],
            'college_graduate' => [
                ['college_graduate', $this->pick(self::COLLEGES), $this->pick(self::COURSES), min($year, $born + 22), $this->chance(18) ? 'Cum Laude' : null],
                [...$secondary, null],
            ],
        };

        // Work history, most recent first, never before the person turned 18.
        $experiences = [];
        $jobs = $age < 21 ? mt_rand(0, 1) : $this->weighted([0 => 25, 1 => 45, 2 => 30]);
        $end = $created->subDays(mt_rand(20, 200));
        $adult = $birth->addYears(18);

        for ($i = 0; $i < $jobs; $i++) {
            $months = mt_rand(6, 36);
            $start = $end->subMonths($months);
            if ($start->lessThan($adult)) {
                break;
            }
            $title = $this->pick(array_keys(self::PAST_JOBS));
            $experiences[] = [$this->pick(self::COMPANIES), $title, $start, $end, $months, self::PAST_JOBS[$title]];
            $end = $start->subDays(mt_rand(15, 120));
        }

        $totalMonths = array_sum(array_column($experiences, 4));
        $skills = [];
        foreach ($this->sample(self::SKILLS[$position] ?? self::SKILLS['General Worker'], mt_rand(2, 4)) as $skill) {
            $skills[] = [
                $skill,
                $totalMonths >= 24 ? $this->pick(['intermediate', 'advanced']) : $this->pick(['beginner', 'intermediate']),
                mt_rand(0, max(1, intdiv($totalMonths, 12))),
            ];
        }

        $certifications = [];
        $pool = self::CERTIFICATIONS[$position] ?? self::CERTIFICATIONS['General Worker'];
        foreach ($this->sample($pool, $this->weighted([0 => 38, 1 => 47, 2 => 15])) as $name) {
            // Most were earned in the last few years; a few lapsed, since
            // expired certificates do not count towards a match.
            $yearsAgo = $age >= 26 && $this->chance(12) ? mt_rand(6, 7) : mt_rand(1, 4);
            $issued = $this->latestOf($adult, $created->subYears($yearsAgo)->subDays(mt_rand(0, 300)));
            $certifications[] = [$name, $this->digits(14), $issued, $issued->addYears(5)];
        }

        return compact('educations', 'experiences', 'skills', 'certifications');
    }

    private function saveQualifications(Applicant $applicant, array $profile, CarbonImmutable $created): void
    {
        $stamp = ['created_at' => $created, 'updated_at' => $created];

        foreach ($profile['educations'] as [$level, $school, $course, $graduated, $honors]) {
            (new ApplicantEducation)->forceFill([
                'applicant_id' => $applicant->id, 'education_level' => $level, 'school_name' => $school,
                'course_program' => $course, 'graduation_year' => $graduated, 'honors' => $honors, ...$stamp,
            ])->save();
        }

        foreach ($profile['experiences'] as [$company, $title, $start, $end, $months, $duties]) {
            (new ApplicantExperience)->forceFill([
                'applicant_id' => $applicant->id, 'company_name' => $company, 'position_title' => $title,
                'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
                'months_experience' => $months, 'responsibilities' => $duties, ...$stamp,
            ])->save();
        }

        foreach ($profile['skills'] as [$skill, $proficiency, $years]) {
            (new ApplicantSkill)->forceFill([
                'applicant_id' => $applicant->id, 'skill_name' => $skill,
                'proficiency_level' => $proficiency, 'years_experience' => $years, ...$stamp,
            ])->save();
        }

        foreach ($profile['certifications'] as [$name, $number, $issued, $expires]) {
            (new ApplicantCertification)->forceFill([
                'applicant_id' => $applicant->id, 'certification_name' => $name, 'cert_number' => $number,
                'issuer' => 'TESDA', 'issued_at' => $issued->toDateString(), 'expires_at' => $expires->toDateString(), ...$stamp,
            ])->save();
        }
    }

    /**
     * The portal account, as registration (online) or HR's "portal access"
     * (everyone else) creates it. No forced password change, so the listed
     * password works on the first sign-in.
     */
    private function createPortalAccount(Applicant $applicant, string $email, string $password, CarbonImmutable $at): void
    {
        $user = User::create([
            'first_name' => $applicant->first_name,
            'middle_name' => $applicant->middle_name,
            'last_name' => $applicant->last_name,
            'suffix' => $applicant->suffix,
            'email' => $email,
            'mobile_number' => $applicant->contact_number,
            'password' => Hash::make($password),
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
            'is_active' => true,
        ]);

        $user->syncRoles(['portal']);
        $user->forceFill(['must_change_password' => false, 'created_at' => $at, 'updated_at' => $at])->save();
    }

    private function credentialRow(?Applicant $applicant, string $email, string $password): array
    {
        $applicant?->refresh();

        return [
            'applicant_code' => $applicant?->applicant_code,
            'full_name' => $applicant?->full_name,
            'status' => $applicant?->current_status,
            'folder' => $applicant?->folder_category,
            'source_channel' => $applicant?->source_channel,
            'preferred_position' => $applicant?->preferred_position,
            'email' => $email,
            'password' => $password,
        ];
    }

    private function writeCredentials(array $rows): void
    {
        usort($rows, fn ($a, $b) => strcmp((string) $a['applicant_code'], (string) $b['applicant_code']));

        $path = storage_path(self::CREDENTIALS_FILE);
        $handle = fopen($path, 'w');
        fputcsv($handle, array_keys($rows[0]), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);

        $this->command?->table(
            ['Code', 'Name', 'Status', 'Email', 'Password'],
            array_map(fn ($r) => [$r['applicant_code'], $r['full_name'], $r['status'], $r['email'], $r['password']], $rows)
        );
        $this->command?->info('Credentials written to '.$path);
    }

    // ------------------------------------------------------------------ helpers

    private function emailFor(array $person): string
    {
        $part = fn (string $name) => preg_replace('/[^a-z]/', '', strtolower(Str::ascii($name)));

        return $part($person[0]).'.'.$part($person[2]).'@'.self::DOMAIN;
    }

    /** Two different words and four digits, from the system's secure random source. */
    private function password(): string
    {
        $words = self::PASSWORD_WORDS;
        $first = random_int(0, count($words) - 1);
        $second = (($first + random_int(1, count($words) - 1)) % count($words));

        return $words[$first].'-'.$words[$second].'-'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    private function phone(): string
    {
        $prefixes = ['0917', '0918', '0919', '0920', '0927', '0935', '0945', '0950', '0956', '0961', '0966', '0977', '0995', '0998'];

        do {
            $number = $this->pick($prefixes).$this->digits(7);
        } while (isset($this->usedPhones[$number]) || Applicant::where('contact_number', $number)->exists());

        $this->usedPhones[$number] = true;

        return $number;
    }

    private function digits(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= mt_rand(0, 9);
        }

        return $out;
    }

    /** A moment between two others, during office hours where the range allows. */
    private function between(CarbonImmutable $from, CarbonImmutable $to): CarbonImmutable
    {
        if ($to->lessThanOrEqualTo($from)) {
            return $from;
        }

        $days = (int) $from->startOfDay()->diffInDays($to->startOfDay(), true);
        $moment = $from->startOfDay()->addDays(mt_rand(0, $days))->setTime(mt_rand(8, 16), mt_rand(0, 59), mt_rand(0, 59));

        if ($moment->lessThan($from)) {
            $moment = $from->addMinutes(mt_rand(5, 45));
        }
        if ($moment->greaterThan($to)) {
            $moment = $to->subMinutes(mt_rand(5, 45));
        }

        return $moment->lessThan($from) ? $from : $moment;
    }

    private function earliest(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->lessThan($b) ? $a : $b;
    }

    private function latestOf(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->greaterThan($b) ? $a : $b;
    }

    private function chance(int $percent): bool
    {
        return mt_rand(1, 100) <= $percent;
    }

    private function pick(array $items): mixed
    {
        $items = array_values($items);

        return $items[mt_rand(0, count($items) - 1)];
    }

    /** @param  array<int|string, int>  $weights  value => weight */
    private function weighted(array $weights): int|string
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $value => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $value;
            }
        }

        return array_key_last($weights);
    }

    private function weightedRow(array $rows, int $weightIndex): array
    {
        $index = $this->weighted(array_map(fn ($row) => $row[$weightIndex], $rows));

        return $rows[$index];
    }

    /** Fisher-Yates on the seeded generator, so the order is reproducible. */
    private function shuffled(array $items): array
    {
        $items = array_values($items);
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }

    private function sample(array $items, int $count): array
    {
        return array_slice($this->shuffled($items), 0, max(0, min($count, count($items))));
    }
}
