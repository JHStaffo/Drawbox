<?php

namespace Database\Seeders;

use App\Models\Protocol;
use App\Models\ProtocolCategory;
use App\Models\ProtocolVersion;
use App\Support\ProtocolChart;
use Illuminate\Database\Seeder;

/**
 * Loads the Cardiac Arrest protocol as a draft, as an example of the format.
 * It still needs Medical Director approval before Team Members see it.
 *
 *   php artisan db:seed --class=ProtocolSampleSeeder
 */
class ProtocolSampleSeeder extends Seeder
{
    public function run(): void
    {
        if (ProtocolVersion::where('title', 'Cardiac Arrest')->exists()) {
            $this->command?->info('A Cardiac Arrest protocol already exists; nothing added. Use php artisan protocols:load-sample to update it.');

            return;
        }
        $version = new ProtocolVersion(self::content() + ['version' => 1, 'title' => 'Cardiac Arrest', 'revised_label' => 'Revised 2020', 'status' => 'draft']);
        $version->search_text = $version->buildSearchText();
        Protocol::create()->versions()->save($version);

        $this->command?->info('Added the Cardiac Arrest protocol as a draft. Send it for approval from Protocols > Build protocols.');
    }

    /** The sample's category, ages, flowchart (arranged to match the printed page) and sections. */
    public static function content(): array
    {
        $category = ProtocolCategory::firstOrCreate(['name' => 'Cardiac Arrest'], ['position' => 1]);

        $box = fn (string $id, string $type, string $text, array $badges = []) => compact('id', 'type', 'text', 'badges') + ['link' => null];
        $sides = [
            'n1>n2' => ['bottom', 'top'],
            'n1>n3' => ['right', 'left'],
            'n3>n4' => ['right', 'left'],
            'n3>n6' => ['bottom', 'top'],
            'n6>n7' => ['left', 'right'],
            'n6>n8' => ['right', 'left'],
            'n6>n9' => ['bottom', 'top'],
            'n7>n10' => ['bottom', 'top'],
            'n10>n11' => ['left', 'right'],
            'n10>n12' => ['bottom', 'top'],
            'n12>n13' => ['bottom', 'top'],
            'n13>n14' => ['left', 'right'],
            'n13>n15' => ['bottom', 'top'],
            'n15>n16' => ['left', 'right'],
            'n15>n17' => ['bottom', 'top'],
            'n17>n10' => ['right', 'right'],
            'n17>n22' => ['bottom', 'left'],
            'n8>n18' => ['bottom', 'top'],
            'n18>n19' => ['bottom', 'top'],
            'n19>n20' => ['right', 'left'],
            'n19>n21' => ['bottom', 'top'],
            'n21>n23' => ['bottom', 'top'],
            'n23>n24' => ['right', 'left'],
            'n23>n25' => ['bottom', 'top'],
            'n25>n26' => ['right', 'left'],
            'n25>n18' => ['left', 'left'],
            'n25>n22' => ['bottom', 'top'],
            'n22>n28' => ['right', 'left'],
        ];
        $arrow = fn (string $from, string $to, ?string $label = null, string $style = 'one') => compact('from', 'to', 'label', 'style')
            + ['from_side' => $sides["{$from}>{$to}"][0] ?? 'auto', 'to_side' => $sides["{$from}>{$to}"][1] ?? 'auto'];
        $cpr = "**Resume CPR**\nFor 2 minutes\n(5 cycles)";
        $epi = "**Epinephrine**\n**1 mg IV/IO**\nRepeat every\n3-5 minutes";
        $defib = "**Continue CPR**\n**Defibrillate once** at\nappropriate setting";

        // Arranged by hand to match the printed protocol.
        $chart = [
            'layout' => 'manual',
            'nodes' => [
                $box('n1', 'decision', 'Pulse?') + ['x' => 230, 'y' => 200],
                $box('n2', 'exit', "**EXIT** to APPROPRIATE\nProtocol") + ['x' => 230, 'y' => 340],
                $box('n3', 'action', "**High Quality CPR**\n**Apply AED/Defibrillator**\nas soon as possible") + ['x' => 610, 'y' => 200],
                $box('n4', 'reference', "Treatment per\nAirway/Oxygenation/Ventilation\nProtocol") + ['x' => 980, 'y' => 200],
                $box('n5', 'note', "Only Paramedics can\nintubate patients 12\nyears old and younger") + ['x' => 980, 'y' => 300],
                $box('n6', 'decision', 'Shockable?') + ['x' => 610, 'y' => 460],
                $box('n9', 'step', "Vascular\nAccess as\nneeded", ['A', 'I', 'P']) + ['x' => 610, 'y' => 610],
                $box('n7', 'action', $cpr) + ['x' => 350, 'y' => 460],
                $box('n10', 'decision', "Adequate\nBLS airway?") + ['x' => 350, 'y' => 610],
                $box('n11', 'step', "Secure\nadvanced\nairway", ['A', 'I', 'P']) + ['x' => 150, 'y' => 610],
                $box('n12', 'step', $epi, ['A', 'I', 'P', 'peds']) + ['x' => 350, 'y' => 790],
                $box('n13', 'decision', "Non Shockable\nRhythm?") + ['x' => 350, 'y' => 950],
                $box('n14', 'step', "Move to\nShockable") + ['x' => 150, 'y' => 950],
                $box('n15', 'decision', 'Pulse?') + ['x' => 350, 'y' => 1130],
                $box('n16', 'exit', "**EXIT** to ROSC\nProtocol") + ['x' => 140, 'y' => 1130],
                $box('n17', 'action', $cpr) + ['x' => 350, 'y' => 1270],
                $box('n8', 'step', $defib) + ['x' => 850, 'y' => 460],
                $box('n18', 'action', $cpr) + ['x' => 850, 'y' => 580],
                $box('n19', 'decision', "Adequate\nBLS airway?") + ['x' => 850, 'y' => 720],
                $box('n20', 'step', "Secure\nadvanced\nairway", ['A', 'I', 'P']) + ['x' => 1080, 'y' => 720],
                $box('n21', 'step', $epi, ['A', 'I', 'P', 'peds']) + ['x' => 850, 'y' => 900],
                $box('n23', 'decision', "Shockable\nrhythm?") + ['x' => 850, 'y' => 1080],
                $box('n24', 'step', "Move to\nNon Shockable") + ['x' => 1070, 'y' => 1080],
                $box('n25', 'action', $defib) + ['x' => 850, 'y' => 1230],
                $box('n26', 'step', "**Amiodarone**\n**300 mg IV/IO**\nRepeat once\n**150 mg IV/IO**", ['I', 'P', 'peds']) + ['x' => 1100, 'y' => 1230],
                $box('n27', 'note', "See Notes for additional medications for the following conditions/patients:\n\nSecondary Antiarrhythmic\nDialysis/Renal pts\nTorsades de pointes") + ['x' => 600, 'y' => 1120],
                $box('n22', 'step', 'Contact Medical Control', ['M']) + ['x' => 670, 'y' => 1360],
                $box('n28', 'terminal', 'Transport') + ['x' => 960, 'y' => 1360],
            ],
            'edges' => [
                $arrow('n1', 'n2', 'YES'),
                $arrow('n1', 'n3', 'NO'),
                $arrow('n3', 'n4', null, 'both'),
                $arrow('n3', 'n6'),
                $arrow('n6', 'n7', 'NO'),
                $arrow('n6', 'n8', 'YES'),
                $arrow('n6', 'n9', null, 'both'),
                $arrow('n7', 'n10'),
                $arrow('n10', 'n11', 'NO', 'both'),
                $arrow('n10', 'n12', 'YES'),
                $arrow('n12', 'n13'),
                $arrow('n13', 'n14', 'NO', 'both'),
                $arrow('n13', 'n15', 'YES'),
                $arrow('n15', 'n16', 'YES'),
                $arrow('n15', 'n17', 'NO'),
                $arrow('n17', 'n10'),
                $arrow('n17', 'n22'),
                $arrow('n8', 'n18'),
                $arrow('n18', 'n19'),
                $arrow('n19', 'n20', 'NO', 'both'),
                $arrow('n19', 'n21', 'YES'),
                $arrow('n21', 'n23'),
                $arrow('n23', 'n24', 'NO', 'both'),
                $arrow('n23', 'n25'),
                $arrow('n25', 'n26', null, 'both'),
                $arrow('n25', 'n18'),
                $arrow('n25', 'n22'),
                $arrow('n22', 'n28'),
            ],
        ];

        $sections = [
            ['heading' => 'Goals', 'color' => 'purple', 'badges' => [], 'text' => <<<'TXT'
- Early recognition and appropriate treatment of cardiac arrest patients
- High quality CPR with minimal interruptions and early defibrillation
- Preservation of neurologic function and ROSC
TXT],
            ['heading' => 'Treatment', 'color' => 'green', 'badges' => [], 'text' => <<<'TXT'
- H's and T's should be addressed AFTER first line medications such as epinephrine and amiodarone and can be added into the cardiac arrest cycle as indicated
- Utilize manual defibrillator as soon as trained provider arrives
- If BLS airway is adequate, priority is vascular access and medication administration
__**Secondary Anti-Arrhythmic**__: Administer **lidocaine 1 mg/kg IV/IO**. Can repeat once at 0.5 mg/kg IV/IO
__**Renal/Dialysis Patient with suspected hyperkalemia**__: Incorporate **calcium chloride 1 gram IV/IO** over 3 minutes and **sodium bicarbonate 1 mEq/kg IV/IO** after first line meds on standing order. Flush well after administration.
__**Torsades**__: **Magnesium sulfate 2 grams IV/IO in 100 mL NS** over 5 minutes is the preferred anti-arrhythmic.
TXT],
            ['heading' => 'Special Considerations', 'color' => 'black', 'badges' => [], 'text' => <<<'TXT'
- Once ALS airway in place, give continuous compressions and adequate breaths, avoid excessive ventilation
- CPR may still be required in the presence of an organized cardiac rhythm
- A moving vehicle may introduce artifact during AED analysis and may lead to inappropriate defibrillation
- Do not place defibrillation pads over transdermal patches and devices such as AICD, pacer, med ports, etc.
- Patients undergoing resuscitation who are pregnant > 20 weeks gestation should be transported rapidly due to potential for fetal viability
__**High Quality CPR**__ - Push hard and fast, 100-120/min, ensure full chest recoil and minimize interruption in compressions, rotate compressors every 2 minutes and check rhythm during switch and minimize time from last compression to defibrillation
__**Contraindications to CPR/AED**__ - Rigor Mortis, Lividity, Injuries incompatible with life, DNR
TXT],
            ['heading' => 'Pediatrics', 'color' => 'pink', 'badges' => ['peds'], 'text' => <<<'TXT'
- Perform CPR if the HR is less than 60 with poor perfusion
- CPR for infants should be performed with the two-thumb encircling technique
- Defibrillation pads should not touch. Use anterior/posterior if needed
- Endotracheal administration of medications should ONLY be used if IV/IO access cannot be obtained
__**AED:**__ Pediatric pads for children less than 8 years is preferred but if unavailable, use adult AED
__**Defibrillation**__: 2 J/kg initial and subsequent at 4 J/kg
__**Epinephrine**__ **0.01 mg/kg IV/IO** up to max dose of 1 mg every 3-5 minutes
  - Endotracheal dose 0.1 mg/kg ETT of 1 mg/1 mL concentration (dilute in 2-5 mL NS)
__**Amiodarone**__ **5 mg/kg IV/IO** up to a max dose of 300 mg and may repeat once
__**Secondary Anti-Arrhythmic**__: Administer **Lidocaine 1 mg/kg IV/IO**. Can repeat once at 0.5 mg/kg IV/IO
__**Renal/Dialysis Patient with suspected hyperkalemia**__: Incorporate **Calcium chloride 20 mg/kg IV/IO** over 3 minutes max dose 1 gram and **Sodium bicarbonate 1 mEq/kg IV/IO**. Flush well after administration
__**Torsades**__: Administer **Magnesium sulfate 50 mg/kg IV/IO in 100 mL NS** over 5 minutes max dose 2 grams
TXT],
        ];

        return [
            'protocol_category_id' => $category->id,
            'category_name' => $category->name,
            'ages' => ['infant', 'pediatric', 'adult'],
            'chart' => ProtocolChart::clean($chart),
            'sections' => $sections,
        ];
    }
}
