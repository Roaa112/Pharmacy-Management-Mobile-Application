<?php

namespace Database\Seeders;

use App\Models\Area;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AreaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $areas = [
            ['name' => '15 May', 'city_id' => 1],
            ['name' => 'Al Azbakeyah', 'city_id' => 1],
            ['name' => 'Al Basatin', 'city_id' => 1],
            ['name' => 'Tebin', 'city_id' => 1],
            ['name' => 'El-Khalifa', 'city_id' => 1],
            ['name' => 'El darrasa', 'city_id' => 1],
            ['name' => 'Aldarb Alahmar', 'city_id' => 1],
            ['name' => 'Zawya al-Hamra', 'city_id' => 1],
            ['name' => 'El-Zaytoun', 'city_id' => 1],
            ['name' => 'Sahel', 'city_id' => 1],
            ['name' => 'El Salam', 'city_id' => 1],
            ['name' => 'Sayeda Zeinab', 'city_id' => 1],
            ['name' => 'El Sharabeya', 'city_id' => 1],
            ['name' => 'Shorouk', 'city_id' => 1],
            ['name' => 'El Daher', 'city_id' => 1],
            ['name' => 'Ataba', 'city_id' => 1],
            ['name' => 'New Cairo', 'city_id' => 1],
            ['name' => 'El Marg', 'city_id' => 1],
            ['name' => 'Ezbet el Nakhl', 'city_id' => 1],
            ['name' => 'Matareya', 'city_id' => 1],
            ['name' => 'Maadi', 'city_id' => 1],
            ['name' => 'Maasara', 'city_id' => 1],
            ['name' => 'Mokattam', 'city_id' => 1],
            ['name' => 'Manyal', 'city_id' => 1],
            ['name' => 'Mosky', 'city_id' => 1],
            ['name' => 'Nozha', 'city_id' => 1],
            ['name' => 'Waily', 'city_id' => 1],
            ['name' => 'Bab al-Shereia', 'city_id' => 1],
            ['name' => 'Bolaq', 'city_id' => 1],
            ['name' => 'Garden City', 'city_id' => 1],
            ['name' => 'Hadayek El-Kobba', 'city_id' => 1],
            ['name' => 'Helwan', 'city_id' => 1],
            ['name' => 'Dar Al Salam', 'city_id' => 1],
            ['name' => 'Shubra', 'city_id' => 1],
            ['name' => 'Tura', 'city_id' => 1],
            ['name' => 'Abdeen', 'city_id' => 1],
            ['name' => 'Abaseya', 'city_id' => 1],
            ['name' => 'Ain Shams', 'city_id' => 1],
            ['name' => 'Nasr City', 'city_id' => 1],
            ['name' => 'New Heliopolis', 'city_id' => 1],
            ['name' => 'Masr Al Qadima', 'city_id' => 1],
            ['name' => 'Mansheya Nasir', 'city_id' => 1],
            ['name' => 'Badr City', 'city_id' => 1],
            ['name' => 'Obour City', 'city_id' => 1],
            ['name' => 'Cairo Downtown', 'city_id' => 1],
            ['name' => 'Zamalek', 'city_id' => 1],
            ['name' => 'Kasr El Nile', 'city_id' => 1],
            ['name' => 'Rehab', 'city_id' => 1],
            ['name' => 'Katameya', 'city_id' => 1],
            ['name' => 'Madinty', 'city_id' => 1],
            ['name' => 'Rod Alfarag', 'city_id' => 1],
            ['name' => 'Sheraton', 'city_id' => 1],
            ['name' => 'El-Gamaleya', 'city_id' => 1],
            ['name' => '10th of Ramadan City', 'city_id' => 1],
            ['name' => 'Helmeyat Alzaytoun', 'city_id' => 1],
            ['name' => 'New Nozha', 'city_id' => 1],
            ['name' => 'Capital New', 'city_id' => 1],

            // Giza Areas
            ['name' => 'Giza', 'city_id' => 2],
            ['name' => 'Sixth of October', 'city_id' => 2],
            ['name' => 'Cheikh Zayed', 'city_id' => 2],
            ['name' => 'Hawamdiyah', 'city_id' => 2],
            ['name' => 'Al Badrasheen', 'city_id' => 2],
            ['name' => 'Saf', 'city_id' => 2],
            ['name' => 'Atfih', 'city_id' => 2],
            ['name' => 'Al Ayat', 'city_id' => 2],
            ['name' => 'Al-Bawaiti', 'city_id' => 2],
            ['name' => 'Manshiyet Al Qanater', 'city_id' => 2],
            ['name' => 'Oaseem', 'city_id' => 2],
            ['name' => 'Kerdasa', 'city_id' => 2],
            ['name' => 'Abu Nomros', 'city_id' => 2],
            ['name' => 'Kafr Ghati', 'city_id' => 2],
            ['name' => 'Manshiyet Al Bakari', 'city_id' => 2],
            ['name' => 'Dokki', 'city_id' => 2],
            ['name' => 'Agouza', 'city_id' => 2],
            ['name' => 'Haram', 'city_id' => 2],
            ['name' => 'Warraq', 'city_id' => 2],
            ['name' => 'Imbaba', 'city_id' => 2],
            ['name' => 'Boulaq Dakrour', 'city_id' => 2],
            ['name' => 'Al Wahat Al Baharia', 'city_id' => 2],
            ['name' => 'Omraneya', 'city_id' => 2],
            ['name' => 'Moneeb', 'city_id' => 2],
            ['name' => 'Bin Alsarayat', 'city_id' => 2],
            ['name' => 'Kit Kat', 'city_id' => 2],
            ['name' => 'Mohandessin', 'city_id' => 2],
            ['name' => 'Faisal', 'city_id' => 2],
            ['name' => 'Abu Rawash', 'city_id' => 2],
            ['name' => 'Hadayek Alahram', 'city_id' => 2],
            ['name' => 'Haraneya', 'city_id' => 2],
            ['name' => 'Hadayek October', 'city_id' => 2],
            ['name' => 'Saft Allaban', 'city_id' => 2],
            ['name' => 'Smart Village', 'city_id' => 2],
            ['name' => 'Ard Ellwaa', 'city_id' => 2],

            // Alexandria Areas (city_id: 3)
            ['name' => 'Abu Qir', 'city_id' => 3],
            ['name' => 'Al Ibrahimeyah', 'city_id' => 3],
            ['name' => 'Azarita', 'city_id' => 3],
            ['name' => 'Anfoushi', 'city_id' => 3],
            ['name' => 'Dekheila', 'city_id' => 3],
            ['name' => 'El Soyof', 'city_id' => 3],
            ['name' => 'Ameria', 'city_id' => 3],
            ['name' => 'El Labban', 'city_id' => 3],
            ['name' => 'Al Mafrouza', 'city_id' => 3],
            ['name' => 'El Montaza', 'city_id' => 3],
            ['name' => 'Mansheya', 'city_id' => 3],
            ['name' => 'Naseria', 'city_id' => 3],
            ['name' => 'Ambrozo', 'city_id' => 3],
            ['name' => 'Bab Sharq', 'city_id' => 3],
            ['name' => 'Bourj Alarab', 'city_id' => 3],
            ['name' => 'Stanley', 'city_id' => 3],
            ['name' => 'Smouha', 'city_id' => 3],
            ['name' => 'Sidi Bishr', 'city_id' => 3],
            ['name' => 'Shads', 'city_id' => 3],
            ['name' => 'Gheet Alenab', 'city_id' => 3],
            ['name' => 'Fleming', 'city_id' => 3],
            ['name' => 'Victoria', 'city_id' => 3],
            ['name' => 'Camp Shizar', 'city_id' => 3],
            ['name' => 'Karmooz', 'city_id' => 3],
            ['name' => 'Mahta Alraml', 'city_id' => 3],
            ['name' => 'Mina El-Basal', 'city_id' => 3],
            ['name' => 'Asafra', 'city_id' => 3],
            ['name' => 'Agamy', 'city_id' => 3],
            ['name' => 'Bakos', 'city_id' => 3],
            ['name' => 'Boulkly', 'city_id' => 3],
            ['name' => 'Cleopatra', 'city_id' => 3],
            ['name' => 'Glim', 'city_id' => 3],
            ['name' => 'Al Mamurah', 'city_id' => 3],
            ['name' => 'Al Mandara', 'city_id' => 3],
            ['name' => 'Moharam Bek', 'city_id' => 3],
            ['name' => 'Elshatby', 'city_id' => 3],
            ['name' => 'Sidi Gaber', 'city_id' => 3],
            ['name' => 'North Coast/sahel', 'city_id' => 3],
            ['name' => 'Alhadra', 'city_id' => 3],
            ['name' => 'Alattarin', 'city_id' => 3],
            ['name' => 'Sidi Kerir', 'city_id' => 3],
            ['name' => 'Elgomrok', 'city_id' => 3],
            ['name' => 'Al Max', 'city_id' => 3],
            ['name' => 'Marina', 'city_id' => 3],

             // Dakahlia Areas (city_id: 4)
             ['name' => 'Mansoura', 'city_id' => 4],
             ['name' => 'Talkha', 'city_id' => 4],
             ['name' => 'Mitt Ghamr', 'city_id' => 4],
             ['name' => 'Dekernes', 'city_id' => 4],
             ['name' => 'Aga', 'city_id' => 4],
             ['name' => 'Menia El Nasr', 'city_id' => 4],
             ['name' => 'Sinbillawin', 'city_id' => 4],
             ['name' => 'El Kurdi', 'city_id' => 4],
             ['name' => 'Bani Ubaid', 'city_id' => 4],
             ['name' => 'Al Manzala', 'city_id' => 4],
             ['name' => 'Tami al\'amdid', 'city_id' => 4],
             ['name' => 'Aljamalia', 'city_id' => 4],
             ['name' => 'Sherbin', 'city_id' => 4],
             ['name' => 'Mataria', 'city_id' => 4],
             ['name' => 'Belqas', 'city_id' => 4],
             ['name' => 'Meet Salsil', 'city_id' => 4],
             ['name' => 'Gamasa', 'city_id' => 4],
             ['name' => 'Mahalat Damana', 'city_id' => 4],
             ['name' => 'Nabroh', 'city_id' => 4],

             
            // Red Sea Areas (city_id: 5)
            ['name' => 'Hurghada', 'city_id' => 5],
            ['name' => 'Ras Ghareb', 'city_id' => 5],
            ['name' => 'Safaga', 'city_id' => 5],
            ['name' => 'El Qusiar', 'city_id' => 5],
            ['name' => 'Marsa Alam', 'city_id' => 5],
            ['name' => 'Shalatin', 'city_id' => 5],
            ['name' => 'Halaib', 'city_id' => 5],
            ['name' => 'Aldahar', 'city_id' => 5],

            // Beheira Areas (city_id: 6)
            ['name' => 'Damanhour - Downtown', 'city_id' => 6],
            ['name' => 'Damanhour - Abu Rish', 'city_id' => 6],
            ['name' => 'Kafr El Dawar - Al Mohagerin', 'city_id' => 6],
            ['name' => 'Kafr El Dawar - Sidi Shahata', 'city_id' => 6],
            ['name' => 'Rashid - Corniche Area', 'city_id' => 6],
            ['name' => 'Edco - City Center', 'city_id' => 6],
            ['name' => 'Abu al-Matamir - Ezbet Al-Nemr', 'city_id' => 6],
            ['name' => 'Abu Homs - Public Square', 'city_id' => 6],
            ['name' => 'Delengat - Army Street', 'city_id' => 6],
            ['name' => 'Mahmoudiyah - Bahr Street', 'city_id' => 6],
            ['name' => 'Rahmaniyah - Rahmaniyah Station', 'city_id' => 6],
            ['name' => 'Itai Baroud - Downtown', 'city_id' => 6],
            ['name' => 'Housh Eissa - Republic Street', 'city_id' => 6],
            ['name' => 'Shubrakhit - Grand Market', 'city_id' => 6],
            ['name' => 'Kom Hamada - Al Attarin Street', 'city_id' => 6],
            ['name' => 'Badr - Industrial Zone', 'city_id' => 6],
            ['name' => 'Wadi Natrun - Desert Road', 'city_id' => 6],
            ['name' => 'New Nubaria - First District', 'city_id' => 6],
            ['name' => 'Alnoubareya - Old Market', 'city_id' => 6],

            // Fayoum Areas (city_id: 7)
            ['name' => 'Fayoum - Downtown', 'city_id' => 7],
            ['name' => 'Fayoum - El Maseed', 'city_id' => 7],
            ['name' => 'Fayoum El Gedida - First District', 'city_id' => 7],
            ['name' => 'Fayoum El Gedida - Second District', 'city_id' => 7],
            ['name' => 'Tamiya - Al Taawun', 'city_id' => 7],
            ['name' => 'Tamiya - El Sadat', 'city_id' => 7],
            ['name' => 'Snores - Downtown', 'city_id' => 7],
            ['name' => 'Snores - Ezbet El Nokta', 'city_id' => 7],
            ['name' => 'Etsa - Main Street', 'city_id' => 7],
            ['name' => 'Epschway - Corniche Street', 'city_id' => 7],
            ['name' => 'Yusuf El Sediaq - Market Area', 'city_id' => 7],
            ['name' => 'Hadqa - University Road', 'city_id' => 7],
            ['name' => 'Atsa - Railway Station', 'city_id' => 7],
            ['name' => 'Algamaa - University Campus', 'city_id' => 7],
            ['name' => 'Sayala - Corniche Road', 'city_id' => 7],

             // Gharbia Areas (city_id: 8)
             ['name' => 'Tanta - Downtown', 'city_id' => 8],
             ['name' => 'Tanta - El Gharby District', 'city_id' => 8],
             ['name' => 'Al Mahalla Al Kobra - First District', 'city_id' => 8],
             ['name' => 'Al Mahalla Al Kobra - Second District', 'city_id' => 8],
             ['name' => 'Kafr El Zayat - Corniche', 'city_id' => 8],
             ['name' => 'Zefta - Downtown', 'city_id' => 8],
             ['name' => 'El Santa - Market Area', 'city_id' => 8],
             ['name' => 'Qutour - Railway Station', 'city_id' => 8],
             ['name' => 'Basion - Main Street', 'city_id' => 8],
             ['name' => 'Samannoud - Industrial Area', 'city_id' => 8],
             
             // Ismailia Areas (city_id: 9)
             ['name' => 'Ismailia - Downtown', 'city_id' => 9],
             ['name' => 'Ismailia - El Salam District', 'city_id' => 9],
             ['name' => 'Fayed - Corniche Area', 'city_id' => 9],
             ['name' => 'Qantara Sharq - New City', 'city_id' => 9],
             ['name' => 'Qantara Gharb - Market Area', 'city_id' => 9],
             ['name' => 'El Tal El Kabier - Main Street', 'city_id' => 9],
             ['name' => 'Abu Sawir - Industrial Zone', 'city_id' => 9],
             ['name' => 'Kasasien El Gedida - Residential Area', 'city_id' => 9],
             ['name' => 'Nefesha - Rural Area', 'city_id' => 9],
             ['name' => 'Sheikh Zayed - New District', 'city_id' => 9],

             // Monufya Areas (city_id: 10)

    ['name' => 'Shbeen El Koom', 'city_id' => 10],
    ['name' => 'Sadat City', 'city_id' => 10],
    ['name' => 'Menouf', 'city_id' => 10],
    ['name' => 'Sars El-Layan', 'city_id' => 10],
    ['name' => 'Ashmon', 'city_id' => 10],
    ['name' => 'Al Bagor', 'city_id' => 10],
    ['name' => 'Quesna', 'city_id' => 10],
    ['name' => 'Berkat El Saba', 'city_id' => 10],
    ['name' => 'Tala', 'city_id' => 10],
    ['name' => 'Al Shohada', 'city_id' => 10],


// Minya Areas (city_id: 11)

    ['name' => 'Minya', 'city_id' => 11],
    ['name' => 'Minya El Gedida', 'city_id' => 11],
    ['name' => 'El Adwa', 'city_id' => 11],
    ['name' => 'Magagha', 'city_id' => 11],
    ['name' => 'Bani Mazar', 'city_id' => 11],
    ['name' => 'Mattay', 'city_id' => 11],
    ['name' => 'Samalut', 'city_id' => 11],
    ['name' => 'Madinat El Fekria', 'city_id' => 11],
    ['name' => 'Meloy', 'city_id' => 11],
    ['name' => 'Deir Mawas', 'city_id' => 11],
    ['name' => 'Abu Qurqas', 'city_id' => 11],
    ['name' => 'Ard Sultan', 'city_id' => 11],


// Qalubia Areas (city_id: 12)

    ['name' => 'Banha', 'city_id' => 12],
    ['name' => 'Qalyub', 'city_id' => 12],
    ['name' => 'Shubra Al Khaimah', 'city_id' => 12],
    ['name' => 'Al Qanater Charity', 'city_id' => 12],
    ['name' => 'Khanka', 'city_id' => 12],
    ['name' => 'Kafr Shukr', 'city_id' => 12],
    ['name' => 'Tukh', 'city_id' => 12],
    ['name' => 'Qaha', 'city_id' => 12],
    ['name' => 'Obour', 'city_id' => 12],
    ['name' => 'Khosous', 'city_id' => 12],
    ['name' => 'Shibin Al Qanater', 'city_id' => 12],
    ['name' => 'Mostorod', 'city_id' => 12],


// New Valley Areas (city_id: 13)

    ['name' => 'El Kharga', 'city_id' => 13],
    ['name' => 'Paris', 'city_id' => 13],
    ['name' => 'Mout', 'city_id' => 13],
    ['name' => 'Farafra', 'city_id' => 13],
    ['name' => 'Balat', 'city_id' => 13],
    ['name' => 'Dakhla', 'city_id' => 13],


// South Sinai Areas (city_id: 14)

    ['name' => 'Suez', 'city_id' => 14],
    ['name' => 'Alganayen', 'city_id' => 14],
    ['name' => 'Ataqah', 'city_id' => 14],
    ['name' => 'Ain Sokhna', 'city_id' => 14],
    ['name' => 'Faysal', 'city_id' => 14],


// Aswan Areas (city_id: 15)

    ['name' => 'Aswan', 'city_id' => 15],
    ['name' => 'Aswan El Gedida', 'city_id' => 15],
    ['name' => 'Drau', 'city_id' => 15],
    ['name' => 'Kom Ombo', 'city_id' => 15],
    ['name' => 'Nasr Al Nuba', 'city_id' => 15],
    ['name' => 'Kalabsha', 'city_id' => 15],
    ['name' => 'Edfu', 'city_id' => 15],
    ['name' => 'Al-Radisiyah', 'city_id' => 15],
    ['name' => 'Al Basilia', 'city_id' => 15],
    ['name' => 'Al Sibaeia', 'city_id' => 15],
    ['name' => 'Abo Simbl Al Siyahia', 'city_id' => 15],
    ['name' => 'Marsa Alam', 'city_id' => 15],

// Assiut Areas (city_id: 16)

    ['name' => 'Assiut', 'city_id' => 16],
    ['name' => 'Assiut El Gedida', 'city_id' => 16],
    ['name' => 'Dayrout', 'city_id' => 16],
    ['name' => 'Manfalut', 'city_id' => 16],
    ['name' => 'Qusiya', 'city_id' => 16],
    ['name' => 'Abnoub', 'city_id' => 16],
    ['name' => 'Abu Tig', 'city_id' => 16],
    ['name' => 'El Ghanaim', 'city_id' => 16],
    ['name' => 'Sahel Selim', 'city_id' => 16],
    ['name' => 'El Badari', 'city_id' => 16],
    ['name' => 'Sidfa', 'city_id' => 16],


// Bani Sweif Areas (city_id: 17)

    ['name' => 'Bani Sweif', 'city_id' => 17],
    ['name' => 'Beni Suef El Gedida', 'city_id' => 17],
    ['name' => 'Al Wasta', 'city_id' => 17],
    ['name' => 'Naser', 'city_id' => 17],
    ['name' => 'Ehnasia', 'city_id' => 17],
    ['name' => 'Beba', 'city_id' => 17],
    ['name' => 'Fashn', 'city_id' => 17],
    ['name' => 'Somasta', 'city_id' => 17],
    ['name' => 'Alabbaseri', 'city_id' => 17],
    ['name' => 'Mokbel', 'city_id' => 17],


// Port Said Areas (city_id: 18)

    ['name' => 'PorSaid', 'city_id' => 18],
    ['name' => 'Port Fouad', 'city_id' => 18],
    ['name' => 'Alarab', 'city_id' => 18],
    ['name' => 'Zohour', 'city_id' => 18],
    ['name' => 'Alsharq', 'city_id' => 18],
    ['name' => 'Aldawahi', 'city_id' => 18],
    ['name' => 'Almanakh', 'city_id' => 18],
    ['name' => 'Mubarak', 'city_id' => 18],


// Damietta Areas (city_id: 19)
    ['name' => 'Damietta', 'city_id' => 19],
    ['name' => 'New Damietta', 'city_id' => 19],
    ['name' => 'Ras El Bar', 'city_id' => 19],
    ['name' => 'Faraskour', 'city_id' => 19],
    ['name' => 'Zarqa', 'city_id' => 19],
    ['name' => 'Alsaru', 'city_id' => 19],
    ['name' => 'Alruwda', 'city_id' => 19],
    ['name' => 'Kafr El-Batikh', 'city_id' => 19],
    ['name' => 'Azbet Al Burg', 'city_id' => 19],
    ['name' => 'Meet Abou Ghalib', 'city_id' => 19],
    ['name' => 'Kafr Saad', 'city_id' => 19],


// Sharqia Areas (city_id: 20)

    ['name' => 'Zagazig', 'city_id' => 20],
    ['name' => 'Al Ashr Men Ramadan', 'city_id' => 20],
    ['name' => 'Minya Al Qamh', 'city_id' => 20],
    ['name' => 'Belbeis', 'city_id' => 20],
    ['name' => 'Mashtoul El Souq', 'city_id' => 20],
    ['name' => 'Qenaiat', 'city_id' => 20],
    ['name' => 'Abu Hammad', 'city_id' => 20],
    ['name' => 'El Qurain', 'city_id' => 20],
    ['name' => 'Hehia', 'city_id' => 20],
    ['name' => 'Abu Kabir', 'city_id' => 20],
    ['name' => 'Faccus', 'city_id' => 20],
    ['name' => 'El Salihia El Gedida', 'city_id' => 20],
    ['name' => 'Al Ibrahimiyah', 'city_id' => 20],
    ['name' => 'Deirb Negm', 'city_id' => 20],
    ['name' => 'Kafr Saqr', 'city_id' => 20],
    ['name' => 'Awlad Saqr', 'city_id' => 20],
    ['name' => 'Husseiniya', 'city_id' => 20],
    ['name' => 'San Alhajar Alqablia', 'city_id' => 20],
    ['name' => 'Manshayat Abu Omar', 'city_id' => 20],

// South Sinai Areas (city_id: 21)

    ['name' => 'Al Toor', 'city_id' => 21],
    ['name' => 'Sharm El-Shaikh', 'city_id' => 21],
    ['name' => 'Dahab', 'city_id' => 21],
    ['name' => 'Nuweiba', 'city_id' => 21],
    ['name' => 'Taba', 'city_id' => 21],
    ['name' => 'Saint Catherine', 'city_id' => 21],
    ['name' => 'Abu Redis', 'city_id' => 21],
    ['name' => 'Abu Zenaima', 'city_id' => 21],
    ['name' => 'Ras Sidr', 'city_id' => 21],


// Kafr El Sheikh Areas (city_id: 22)

    ['name' => 'Kafr El Sheikh', 'city_id' => 22],
    ['name' => 'Kafr El Sheikh Downtown', 'city_id' => 22],
    ['name' => 'Desouq', 'city_id' => 22],
    ['name' => 'Fooh', 'city_id' => 22],
    ['name' => 'Metobas', 'city_id' => 22],
    ['name' => 'Burg Al Burullus', 'city_id' => 22],
    ['name' => 'Baltim', 'city_id' => 22],
    ['name' => 'Masief Baltim', 'city_id' => 22],
    ['name' => 'Hamol', 'city_id' => 22],
    ['name' => 'Bella', 'city_id' => 22],
    ['name' => 'Riyadh', 'city_id' => 22],
    ['name' => 'Sidi Salm', 'city_id' => 22],
    ['name' => 'Qellen', 'city_id' => 22],
    ['name' => 'Sidi Ghazi', 'city_id' => 22],


// Matrouh Areas (city_id: 23)

    ['name' => 'Marsa Matrouh', 'city_id' => 23],
    ['name' => 'El Hamam', 'city_id' => 23],
    ['name' => 'Alamein', 'city_id' => 23],
    ['name' => 'Dabaa', 'city_id' => 23],
    ['name' => 'Al-Nagila', 'city_id' => 23],
    ['name' => 'Sidi Brani', 'city_id' => 23],
    ['name' => 'Salloum', 'city_id' => 23],
    ['name' => 'Siwa', 'city_id' => 23],
    ['name' => 'Marina', 'city_id' => 23],
    ['name' => 'North Coast', 'city_id' => 23],


// Luxor Areas (city_id: 24)

    ['name' => 'Luxor', 'city_id' => 24],
    ['name' => 'New Luxor', 'city_id' => 24],
    ['name' => 'Esna', 'city_id' => 24],
    ['name' => 'New Tiba', 'city_id' => 24],
    ['name' => 'Al ziynia', 'city_id' => 24],
    ['name' => 'Al Bayadieh', 'city_id' => 24],
    ['name' => 'Al Qarna', 'city_id' => 24],
    ['name' => 'Armant', 'city_id' => 24],
    ['name' => 'Al Tud', 'city_id' => 24],

// Qena Areas (city_id: 25)
    ['name' => 'Qena', 'city_id' => 25],
    ['name' => 'New Qena', 'city_id' => 25],
    ['name' => 'Abu Tesht', 'city_id' => 25],
    ['name' => 'Nag Hammadi', 'city_id' => 25],
    ['name' => 'Deshna', 'city_id' => 25],
    ['name' => 'Alwaqf', 'city_id' => 25],
    ['name' => 'Qaft', 'city_id' => 25],
    ['name' => 'Naqada', 'city_id' => 25],
    ['name' => 'Farshout', 'city_id' => 25],
    ['name' => 'Quos', 'city_id' => 25],

    ['name' => 'Arish', 'city_id' => 26],
    ['name' => 'Sheikh Zowaid', 'city_id' => 26],
    ['name' => 'Nakhl', 'city_id' => 26],
    ['name' => 'Rafah', 'city_id' => 26],
    ['name' => 'Bir al-Abed', 'city_id' => 26],
    ['name' => 'Al Hasana', 'city_id' => 26],
    
// Sohag Areas (city_id: 27)
    ['name' => 'Sohag', 'city_id' => 27],
    ['name' => 'Sohag El Gedida', 'city_id' => 27],
    ['name' => 'Akhmeem', 'city_id' => 27],
    ['name' => 'Akhmim El Gedida', 'city_id' => 27],
    ['name' => 'Albalina', 'city_id' => 27],
    ['name' => 'El Maragha', 'city_id' => 27],
    ['name' => 'almunsha\'a', 'city_id' => 27],
    ['name' => 'Dar AISalaam', 'city_id' => 27],
    ['name' => 'Gerga', 'city_id' => 27],
    ['name' => 'Jahina Al Gharbia', 'city_id' => 27],
    ['name' => 'Saqilatuh', 'city_id' => 27],
    ['name' => 'Tama', 'city_id' => 27],
    ['name' => 'Tahta', 'city_id' => 27],
    ['name' => 'Alkawthar', 'city_id' => 27],

    
        ];


        

        foreach ($areas as $area) {
            Area::create($area);
        }
    }
}
