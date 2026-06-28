-- Barcode qty scanning (Phase 1): seed Czerlop product ids onto products.
-- Portable + environment-independent: keyed by SKU (the stable business key),
-- NOT by product id. Run on LOCAL and PRODUCTION after the ADD COLUMN migration.
-- Generated from 'Czerlop Scale.xlsx' grid positions (page model, ids 1..185).
-- Safe to re-run (UPDATEs are idempotent).
--
-- Auto-seeds 136 Czerlop ids covering 268 SKUs.
-- Items needing MANUAL attention afterwards are listed at the bottom of this file.

-- #1  Mutton Leg (Raan) Boti cut
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 1 WHERE v.sku IN ('M2-LGB', 'M2-LGT', 'P2-LGB', 'P2-LGT');

-- #2  Mutton Back Chops (Puth)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 2 WHERE v.sku IN ('M3-BCP', 'M4-BCP', 'M4-BCS', 'M4-BCT', 'P3-BCP', 'P3-BCP1', 'P3-BCT');

-- #3  Mutton Shoulder (Dasti)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 3 WHERE v.sku IN ('M12-DT', 'P12-DT');

-- #4  Mutton Front Chops (Chaanmp)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 4 WHERE v.sku IN ('M4-FCP', 'P4-FCP');

-- #5  Mutton Neck (Gardan)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 5 WHERE v.sku IN ('P12-NK');

-- #6  Mutton Chest (Seena)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 6 WHERE v.sku IN ('M5-CBR', 'P5-CBR');

-- #7  Mutton Minced (Qeema)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 7 WHERE v.sku IN ('M6-MC', 'P6-MC');

-- #8  Mutton Joints
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 8 WHERE v.sku IN ('M13-JS', 'P13-JS');

-- #9  Mutton Mix Boti
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 9 WHERE v.sku IN ('M6-MB', 'P6-MB');

-- #10  Mutton Boneless
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 10 WHERE v.sku IN ('M7-BL', 'P7-BL');

-- #11  Mutton Kunna Cut
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 11 WHERE v.sku IN ('M16-KC', 'P16-KC');

-- #12  Mutton Liver (Kaleji)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 12 WHERE v.sku IN ('M9-LH', 'M9-LV1', 'P9-LH', 'P9-LV1');

-- #13  Mutton Brain (Maghaz)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 13 WHERE v.sku IN ('M8-BR-1');

-- #14  Goat Trotters (Paaye) Small
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 14 WHERE v.sku IN ('M7-TR3');

-- #15  Goat Trotters (Paaye) Medium
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 15 WHERE v.sku IN ('M7-TR');

-- #16  Goat Trotters (Paaye) Large
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 16 WHERE v.sku IN ('M7-TR1');

-- #17  Goat Trotters (Paaye) Executive
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 17 WHERE v.sku IN ('M7-TR3E');

-- #18  Mutton Soup Bones
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 18 WHERE v.sku IN ('M16-YK');

-- #19  Veal Boneless Boti
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 19 WHERE v.sku IN ('B1-BL', 'PB-BL');

-- #20  Veal Prime Boneless Boti
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 20 WHERE v.sku IN ('PB-PBC', 'PB-PBH');

-- #21  Veal Mined (Qeema)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 21 WHERE v.sku IN ('B3-MC', 'PB-MC');

-- #22  Veal Mix Boti
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 22 WHERE v.sku IN ('B2-MB', 'P2-MB');

-- #23  Veal Back Chops (Puth)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 23 WHERE v.sku IN ('B2-PT', 'P2-PT');

-- #24  Veal Shank Boneless (Nihari)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 24 WHERE v.sku IN ('B5-NH12', 'PB4-NH');

-- #25  Veal Bone (Nalli)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 25 WHERE v.sku IN ('B10-NL');

-- #26  Veal Tenderloin (Undercut)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 26 WHERE v.sku IN ('B8-UCH', 'KL-UCH', 'PB-UCH');

-- #27  Veal Cutlets (Pasanday)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 27 WHERE v.sku IN ('B12-PS', 'B12-PSH');

-- #28  Veal Steak Cut T-Bone Steak
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 28 WHERE v.sku IN ('PB-TBS');

-- #29  Veal Steak Cut Tomahawk Steak
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 29 WHERE v.sku IN ('PB-THS');

-- #30  Veal Steak Cut Sirloin Steak
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 30 WHERE v.sku IN ('PB-SSC', 'PB-SSF');

-- #31  Veal Rib Steak
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 31 WHERE v.sku IN ('PB-RS');

-- #32  Veal Brisket
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 32 WHERE v.sku IN ('B20-BT', 'PB11-BB');

-- #33  Cow Trotter (Paaye)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 33 WHERE v.sku IN ('B6-TC');

-- #34  Buffalo Trotter (Paaye)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 34 WHERE v.sku IN ('PB6-TB');

-- #35  Veal Minced (Qeema) Mota Qeema
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 35 WHERE v.sku IN ('B4-MQ', 'PB4-MQ');

-- #36  Veal Bihari Boti
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 36 WHERE v.sku IN ('B10-BB', 'B5-BB');

-- #37  Veal Tenderloin (Undercut) Beef Chili Strips
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 37 WHERE v.sku IN ('B12-BCD', 'P12-BCD');

-- #38  Veal Hunter Beef
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 38 WHERE v.sku IN ('PB12-HB');

-- #39  Veal Boneless Loaf (Bota)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 39 WHERE v.sku IN ('B2-BL', 'P2-BL');

-- #40  Chicken Karahi Cut (22 Pieces)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 40 WHERE v.sku IN ('CH-WH', 'PH-WH');

-- #41  Chicken Whole with Skin
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 41 WHERE v.sku IN ('PH-WSR');

-- #42  Chicken Tikka Leg Pieces
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 42 WHERE v.sku IN ('CH-LT', 'PH-LT');

-- #43  Chicken Tikka Breast Pieces
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 43 WHERE v.sku IN ('CH-BT', 'PH-BT');

-- #44  Chicken Thigh Whole
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 44 WHERE v.sku IN ('CH-TH', 'PH-TH');

-- #45  Chicken Thigh Boneless
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 45 WHERE v.sku IN ('CH-TB', 'PH-TB');

-- #46  Chicken Drumsticks
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 46 WHERE v.sku IN ('CH-DT', 'PH-DT');

-- #47  Chicken Boneless Cubes
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 47 WHERE v.sku IN ('CH-CBS', 'PH-CBS');

-- #48  Chicken Thigh Cut 3 Pieces
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 48 WHERE v.sku IN ('CH-TH3', 'PH-TH3');

-- #49  Chicken Boneless Steak Fillet
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 49 WHERE v.sku IN ('CH-SF', 'PH-SF');

-- #50  Chicken Boneless Julienne Cut
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 50 WHERE v.sku IN ('CH-JC', 'PH-JC');

-- #51  Chicken Boneless Breast Butterfly
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 51 WHERE v.sku IN ('CH-BB', 'PH-BB');

-- #52  Desi Chicken - Organic
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 52 WHERE v.sku IN ('CH-DCO');

-- #53  Desi Chicken - Aseel
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 53 WHERE v.sku IN ('CH-DCA');

-- #54  Chicken Minced (Qeema)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 54 WHERE v.sku IN ('CH-MC', 'PH-MC');

-- #55  Whole Chicken Roast
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 55 WHERE v.sku IN ('CH-WR', 'PH-WR');

-- #56  Chicken Tikka Cut (4 Pieces)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 56 WHERE v.sku IN ('CH-TC4', 'PH-TC4');

-- #57  Chicken Mandi Cut (8 Pieces)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 57 WHERE v.sku IN ('CH-MDC8', 'PH-MDC8');

-- #58  Chicken Biryani Cut (12 Pieces)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 58 WHERE v.sku IN ('CH-BC', 'PH-BC');

-- #59  Chicken Qorma Cut (16 Pieces)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 59 WHERE v.sku IN ('CH-QC', 'PH-QC');

-- #60  Mutton Kidney (Gurday)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 60 WHERE v.sku IN ('M14-K2', 'M14-KD');

-- #61  Mutton Testicles (Kapooray)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 61 WHERE v.sku IN ('M16-TC', 'M16-TT');

-- #62  Chicken Wings
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 62 WHERE v.sku IN ('CH-WC');

-- #63  Chicken Neck
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 63 WHERE v.sku IN ('CH-NK');

-- #64  Mutton Leg (Raan) Roast
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 64 WHERE v.sku IN ('M2-LGR1', 'PM2-LGR');

-- #65  Mutton Back Chops (Puth) Steam Cut
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 65 WHERE v.sku IN ('P4-BCT', 'P4-BSP');

-- #66  Mutton Shoulder (Dasti) Roast
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 66 WHERE v.sku IN ('M12-DR', 'P12-DR');

-- #67  Mutton Fry Chops Lahori Tawa Chops
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 67 WHERE v.sku IN ('M4-LTC', 'P4-LTC');

-- #69  Mutton Minced (Qeema) Hand Chopped
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 69 WHERE v.sku IN ('M6-HC', 'P6-HC');

-- #70  Mutton Minced (Qeema) Mota Qeeam
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 70 WHERE v.sku IN ('M6-MQ', 'P6-MQ');

-- #71  Mutton Joints Cross Cut
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 71 WHERE v.sku IN ('M13-JCC', 'P13-JCC');

-- #72  Lamb Mix Boti
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 72 WHERE v.sku IN ('M15-LMB', 'P15-LMB');

-- #73  Lamb Tail fat (Chakki)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 73 WHERE v.sku IN ('M21-LF');

-- #74  Mutton Flanks (Pallay)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 74 WHERE v.sku IN ('M8-FP', 'P8-FP');

-- #75  Veal Liver (Kaleji)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 75 WHERE v.sku IN ('B15-KL', 'B15-KWH', 'P15-KL', 'P15-KWH');

-- #76  Cow Brain
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 76 WHERE v.sku IN ('PB7-BR');

-- #77  Mutton Fat
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 77 WHERE v.sku IN ('M15-FC');

-- #78  Veal Fat
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 78 WHERE v.sku IN ('B12-FT');

-- #79  Mutton Lungs (Phipra)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 79 WHERE v.sku IN ('M15-LG');

-- #80  Mutton Siri with Brain
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 80 WHERE v.sku IN ('M12-SR', 'M13-BSR');

-- #81  Mutton Bones Haddi Guddi
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 81 WHERE v.sku IN ('M6-BHG');

-- #84  Veal Minced (Qeema) burgers
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 84 WHERE v.sku IN ('KL-VMC', 'MCG-VMC', 'PB-MBP');

-- #85  Veal Tendons (Nuss)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 85 WHERE v.sku IN ('B6-TD');

-- #86  Veal Shank Boneless (Nihari) Diced Cut
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 86 WHERE v.sku IN ('B5-PVSB', 'B5-VSBB');

-- #87  Veal Shank Boneless (Nihari) Boti Cut
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 87 WHERE v.sku IN ('B5-NCB', 'P5-NCB');

-- #88  Veal Soup Bones
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 88 WHERE v.sku IN ('B11-SB');

-- #89  Veal Tenderloin (Undercut) Fillet Mignon
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 89 WHERE v.sku IN ('B8-FM', 'B8-FMUH', 'PB-FM', 'PB-FMUH');

-- #93  Veal Tenderloin (Undercut) Burger Mince
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 93 WHERE v.sku IN ('PB-TMC');

-- #94  Veal Oxtail
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 94 WHERE v.sku IN ('B10-OXT', 'PB-OXT');

-- #96  Nizami Farms Qurbani Cow Share (Hissa)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 96 WHERE v.sku IN ('QUR-CSH-D1', 'QUR-CSH-D2', 'QUR-CSH-D3');

-- #97  Nizami Farms Qurbani Goat (Bakra)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 97 WHERE v.sku IN ('QUR-GB-D1', 'QUR-GB-D2', 'QUR-GB-D3');

-- #98  Veal Minced (Qeema) Hand Chopped
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 98 WHERE v.sku IN ('PB-MCH');

-- #100  Veal Tenderloin (Undercut) Stir Fry
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 100 WHERE v.sku IN ('B12-BSF', 'P12-BSF');

-- #101  Veal Sirloin Tip Roast
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 101 WHERE v.sku IN ('B10-STR', 'PB-STR');

-- #102  Veal Rump Bones
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 102 WHERE v.sku IN ('B15-RB');

-- #104  Chicken with Skin Wings
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 104 WHERE v.sku IN ('CS-WC', 'CS-WG');

-- #105  Chicken with Skin Leg Tikka
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 105 WHERE v.sku IN ('PS-LT');

-- #106  Chicken Leg Tikka Boti Cut
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 106 WHERE v.sku IN ('CH-L3', 'PH-L3');

-- #107  Chicken with Skin Thigh
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 107 WHERE v.sku IN ('PS-TH');

-- #108  Chicken with Skin Thigh Boneless
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 108 WHERE v.sku IN ('PS-TB');

-- #109  Chicken with Skin Drumsticks
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 109 WHERE v.sku IN ('PS-DT');

-- #110  Chicken Breast Tenderloins
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 110 WHERE v.sku IN ('CH-BTL', 'PH-BTL');

-- #111  Table Talk Mutton Leg (Raan)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 111 WHERE v.sku IN ('TT-MLR');

-- #112  Sunny Kitchen Veal Tenderloin (Undercut)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 112 WHERE v.sku IN ('SK-UC');

-- #113  Cafe Rustic Veal Bone Nalli
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 113 WHERE v.sku IN ('CR-VBN');

-- #114  Cafe Rustic Veal Tenderloin Undercut
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 114 WHERE v.sku IN ('CR-UC');

-- #115  Table Talk Mutton Boneless
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 115 WHERE v.sku IN ('TT-MBL');

-- #116  Table Talk Mutton Front Chops
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 116 WHERE v.sku IN ('TT-MFC');

-- #117  Table Talk Veal Shank Boneless
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 117 WHERE v.sku IN ('TT-VSB');

-- #118  Table Talk Veal Pasanday
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 118 WHERE v.sku IN ('TT-VCP');

-- #119  Beef Lungs (Phipra)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 119 WHERE v.sku IN ('DF-LP');

-- #120  Beef Tripe (Ojhri)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 120 WHERE v.sku IN ('DF-BT');

-- #125  Chicken Wings 2 Pcs
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 125 WHERE v.sku IN ('CH-WG');

-- #127  Mutton Whole Bakra
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 127 WHERE v.sku IN ('M1-WB10', 'M1-WB11', 'M1-WB12', 'M1-WB9', 'P1-LB10', 'P1-LB7', 'P1-LB8', 'P1-LB9');

-- #128  Mutton Half Bakra (Leg to Shoulder)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 128 WHERE v.sku IN ('M1-HB1', 'M1-HB2', 'M1-HB3', 'M1-HB4', 'P1-HB5', 'P1-HB6', 'P1-HB7', 'P1-HB8');

-- #129  Mutton Raan Puth Mix
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 129 WHERE v.sku IN ('M2-RPM', 'M2-RPTM', 'PM2-RPKT', 'PM2-RPM');

-- #130  Mutton Front Chops Double Chops
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 130 WHERE v.sku IN ('M4-FDP', 'P4-FDP');

-- #131  Mutton Raan Gardan Mix
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 131 WHERE v.sku IN ('M2-LNM', 'M2-LNTM', 'P2-LNM', 'P2-LNT');

-- #132  Mutton Raan Chest Mix
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 132 WHERE v.sku IN ('M2-LCM', 'M2-LCTM', 'P2-LCM', 'P2-LCT');

-- #133  Mutton Raan Chanmp Mix
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 133 WHERE v.sku IN ('M2-RCM', 'M2-RCT', 'P2-RCM');

-- #134  Mutton Tail (Dum)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 134 WHERE v.sku IN ('M10-TDC', 'M10-WTD');

-- #135  Mutton Ribs
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 135 WHERE v.sku IN ('M5-RBS', 'P5-RBS');

-- #136  Mutton Tripe (Ojhri)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 136 WHERE v.sku IN ('R10-TP');

-- #137  Mutton Half Bakra (Shoulder Half)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 137 WHERE v.sku IN ('M2-HB1', 'M2-HB2', 'M2-HB3', 'M2-HB4', 'P2-HB5', 'P2-HB6', 'P2-HB7', 'P2-HB8');

-- #138  Mutton Half Bakra (Adhra)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 138 WHERE v.sku IN ('M3-HB1', 'M3-HB2', 'M3-HB3', 'M3-HB4', 'P3-HB5', 'P3-HB6', 'P3-HB7', 'P3-HB8');

-- #139  Mutton Cutlets (Pasanday)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 139 WHERE v.sku IN ('B7-CP', 'P7-CP');

-- #140  Mutton Leg (Raan) Custom Cut
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 140 WHERE v.sku IN ('P2-LGMC');

-- #141  Lamb Shanks Joints
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 141 WHERE v.sku IN ('P13-LSL', 'P13-LSS');

-- #142  Mutton Lamb Minced (Qeema)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 142 WHERE v.sku IN ('P6-LMC');

-- #144  Veal Marrow Bone Cross Cut
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 144 WHERE v.sku IN ('B10-CCR');

-- #145  Veal Marrow Bone Canoe Cut
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 145 WHERE v.sku IN ('B11-CCL');

-- #146  Veal Prime Boneless Boti - Tikka Cut
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 146 WHERE v.sku IN ('PB-TBCH', 'PB-TBCK');

-- #147  Veal Chest (Seena) Dakri Cut
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 147 WHERE v.sku IN ('B2-DC', 'P2-DC');

-- #148  Veal Shank on the Bone (Ossobuco)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 148 WHERE v.sku IN ('B6-SOB');

-- #149  Veal Shank - Thor Hammer Bone In
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 149 WHERE v.sku IN ('B7-THH');

-- #150  Veal Mix Boti (Kabuli Pulao)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 150 WHERE v.sku IN ('B2-MKC', 'P2-MKC');

-- #153  Veal Tenderloin (Undercut) Cutlets (Pasanday)
UPDATE `t_crm_prod_product` p JOIN `t_crm_prod_product_variant` v ON v.product_id = p.id
  SET p.czerlop_product_id = 153 WHERE v.sku IN ('B12-UPS', 'P12-UPS');

-- ===========================================================================
-- MANUAL ATTENTION (NOT auto-applied above) — resolve via the product screen:
-- ===========================================================================
--
-- (a) SKUs listed under TWO Czerlop ids — pick the correct one per product:
--      B6-NHL  -> ids 90, 91
--      B6-NHD  -> ids 90, 91
--      P13-UBB  -> ids 92, 99
--
-- (b) Products reached by SKUs with conflicting ids (excluded to stay safe):
--      (none)
--
-- (c) Excel SKUs not found in this DB (check the SKU):
--      B1-KG
--
-- (d) Czerlop slots with NO SKU yet — manager must create the product, then tag it:
--      #68  Arabian Lamb Chops
--      #82  Mutton
--      #83  Veal
--      #103  Chicken Thigh Boneless Boti Cut
--      #121  Pet Food
--      #122  Chicken
--      #123  Mutton Waste
--      #124  Chicken Soup Bones
--      #126  Chicken Waste
--      #151  Veal Tongue (Zuban)
--      #152  Veal Boneless Boti Haleem Cut
--      #156  Veal Heart
--      #162  Veal Flank Steak
--      #164  Veal Chuck Roast
--      #165  Veal Boneless Boti Haleem Cut
--      #182  Spring Rolls Thigh Boneless
--      #183  Chicken Cheese Samosa Thigh Boneless
--      #184  Chicken Thigh Qeema Chicken Samosa
--      #185  Reshmi Kabab Thigh Boneless Qeema
